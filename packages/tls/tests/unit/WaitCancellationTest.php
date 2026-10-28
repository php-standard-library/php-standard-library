<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Revolt\EventLoop;

use function array_filter;
use function array_slice;
use function array_values;
use function fclose;
use function fread;
use function fwrite;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;

use const PHP_OS_FAMILY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * A waiting lazy accept, handshake or write settles once, and its cancellation listener reaches only that wait: not
 * before the wait has suspended, and not a later wait of the same fiber, which the event loop hands the same
 * suspension.
 */
#[AllowMockObjectsWithoutExpectations]
final class WaitCancellationTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';

    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * How many bytes one write to the socket pair offers while filling or draining its buffer.
     */
    private const int CHUNK_SIZE = 65_536;

    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int DEADLINE_SECONDS = 5;

    /**
     * The socket pairs the write tests created, closed after each test.
     *
     * @var list<resource>
     */
    private array $resources = [];

    /**
     * Close the socket pairs the test created.
     */
    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->resources as $resource) {
            @fclose($resource);
        }

        $this->resources = [];
    }

    /**
     * A deadline that passes between the lazy accept's check and its subscribe fails the accept with the token's
     * exception, and the stream can still be inspected.
     */
    public function testLazyAcceptFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $this->assertLazyAcceptFailsWithTheTokenAndKeepsTheStream(new ExpiringCancellationToken());
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the lazy accept with the token's exception, and
     * the stream can still be inspected.
     */
    public function testLazyAcceptFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $this->assertLazyAcceptFailsWithTheTokenAndKeepsTheStream(new InlineCancellationToken());
    }

    /**
     * The listener of a lazy accept that has returned, notified from a snapshot taken while it waited, does not end
     * the next lazy accept of the same fiber.
     */
    public function testLateCancellationOfAnEarlierLazyAcceptDoesNotEndALaterOne(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();
        $token = new DeferredCancellationToken();
        $firstHello = new Async\Deferred();
        $configuration = self::serverConfiguration();

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
            $plain = $listener->accept($deadline);
            $server = Async\run(static function () use (
                $listener,
                $plain,
                $token,
                $firstHello,
                $configuration,
                $deadline,
            ): string {
                $lazy = TLS\LazyAcceptor::default();
                $first = $lazy->accept($plain, $token);
                $firstHello->complete($first->getServerName());
                $first->complete($configuration, $deadline)->close();

                // A second wait of the same fiber, which does not use the token.
                $second = $lazy->accept($listener->accept($deadline));
                $second->complete($configuration, $deadline)->close();

                return (string) $second->getServerName();
            });

            // The server waits for the first ClientHello, subscribed to the token. The token is cancelled, but its
            // listeners are notified only later, after that accept has returned.
            Async\later();
            $token->cancel();
            $first = Async\run(static function () use ($client, $deadline): void {
                self::handshakeAndAwaitClose($client, 'first.example.test', $deadline);
            });
            static::assertSame('first.example.test', $firstHello->getAwaitable()->await());
            $first->await();

            // The server now waits for the second connection; the first accept's listener must not end it.
            $token->deliver();
            self::handshake($listener, 'second.example.test', $deadline);

            static::assertSame('second.example.test', $server->await());
        } finally {
            $listener->close();
        }
    }

    /**
     * A deadline that passes between the handshake's check and its subscribe fails the handshake with the token's
     * exception.
     */
    public function testHandshakeFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $this->assertHandshakeFailsWithTheToken(new ExpiringCancellationToken());
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the handshake with the token's exception.
     */
    public function testHandshakeFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $this->assertHandshakeFailsWithTheToken(new InlineCancellationToken());
    }

    /**
     * The listener of a handshake that has completed, notified from a snapshot taken while it waited, does not end
     * the next wait of the same fiber.
     */
    public function testLateCancellationOfAnEarlierHandshakeDoesNotEndALaterWait(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();
        $token = new DeferredCancellationToken();
        $handshake = new Async\Deferred();

        try {
            $client = Async\run(static function () use ($listener, $token, $handshake, $deadline): string {
                $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
                $stream = self::connector()->connect($plain, 'localhost', $token);
                $handshake->complete(null);

                try {
                    // A second wait of the same fiber, which does not use the token.
                    return $stream->readFixedSize(5);
                } finally {
                    $stream->close();
                }
            });

            // The client waits for the server's handshake messages, subscribed to the token: it subscribes in the same
            // step that sends its ClientHello. The token is cancelled, but its listeners are notified only later, after
            // the handshake has completed.
            $plain = $listener->accept($deadline);
            static::assertNotSame('', $plain->peek(1, $deadline));
            $token->cancel();
            $server = new TLS\Acceptor(self::serverConfiguration())->accept($plain, $deadline);
            $handshake->getAwaitable()->await();

            // The client now waits for bytes; the handshake's listener must not end that wait.
            $token->deliver();
            $server->writeAll('hello', $deadline);

            static::assertSame('hello', $client->await());
            $server->close();
        } finally {
            $listener->close();
        }
    }

    /**
     * A deadline that passes between the check of a write waiting on a pending record and its subscribe fails the
     * write with the token's exception, and the record still goes out first.
     */
    public function testWriteFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $this->assertWriteFailsWithTheTokenAndKeepsTheRecord(new ExpiringCancellationToken());
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails a write waiting on a pending record with the
     * token's exception, and the record still goes out first.
     */
    public function testWriteFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $this->assertWriteFailsWithTheTokenAndKeepsTheRecord(new InlineCancellationToken());
    }

    /**
     * The listener of a write that has returned, notified from a snapshot taken while it waited on a pending record,
     * does not end the next write of the same fiber.
     */
    public function testLateCancellationOfAnEarlierWriteDoesNotEndALaterWrite(): void
    {
        $calls = [];
        $sendable = false;
        [$inner, $resource, $peer] = $this->createInner(static function (string $bytes) use (&$calls, &$sendable): int {
            $calls[] = $bytes;

            return $sendable ? strlen($bytes) : 0;
        });
        $stream = self::wrap($inner);
        $token = new DeferredCancellationToken();
        $firstWrite = new Async\Deferred();

        // A short tryWrite() leaves its record pending.
        static::assertSame(3, $stream->tryWrite('AAA'));

        $writer = Async\run(static function () use ($stream, $token, $firstWrite, $resource, &$sendable): int {
            $firstWrite->complete($stream->write('BBB', $token));

            // The socket takes nothing again, and the next record stays pending.
            $sendable = false;
            self::fill($resource);
            static::assertSame(3, $stream->tryWrite('CCC'));

            // A second wait of the same fiber, which does not use the token.
            return $stream->write('DDD');
        });

        // The writer waits for the socket to take the pending record, subscribed to the token. The token is
        // cancelled, but its listeners are notified only later, after that write has returned.
        Async\later();
        $token->cancel();
        $sendable = true;
        self::drain($peer);
        static::assertSame(3, $firstWrite->getAwaitable()->await());

        // The writer now waits on the next pending record; the first write's listener must not end it.
        Async\later();
        $token->deliver();
        $sendable = true;
        self::drain($peer);

        static::assertSame(3, $writer->await());
        static::assertSame(['CCC', 'DDD'], array_slice($calls, -2));
    }

    /**
     * Fail a lazy accept with the given token, then inspect and complete a handshake on the same stream.
     */
    private function assertLazyAcceptFailsWithTheTokenAndKeepsTheStream(ExpiringCancellationToken|InlineCancellationToken $cancellation): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
            $plain = $listener->accept($deadline);
            $callbacks = self::enabledCallbacks();
            $lazy = TLS\LazyAcceptor::default();

            $failure = null;
            try {
                $lazy->accept($plain, $cancellation);
            } catch (Async\Exception\CancelledException $e) {
                $failure = $e;
            }

            static::assertSame($cancellation->exception, $failure);
            static::assertSame($callbacks, self::enabledCallbacks());

            [$serverName] = Async\concurrently([
                static function () use ($lazy, $plain, $deadline): null|string {
                    $hello = $lazy->accept($plain, $deadline);
                    $hello->complete(self::serverConfiguration(), $deadline)->close();

                    return $hello->getServerName();
                },
                static function () use ($client, $deadline): void {
                    self::handshakeAndAwaitClose($client, 'localhost', $deadline);
                },
            ]);

            static::assertSame('localhost', $serverName);
        } finally {
            $listener->close();
        }
    }

    /**
     * Fail a client handshake with the given token while the peer has not answered yet.
     */
    private function assertHandshakeFailsWithTheToken(ExpiringCancellationToken|InlineCancellationToken $cancellation): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
            $server = $listener->accept($deadline);
            $callbacks = self::enabledCallbacks();

            $failure = null;
            try {
                self::connector()->connect($client, 'localhost', $cancellation);
            } catch (Async\Exception\CancelledException $e) {
                $failure = $e;
            }

            static::assertSame($cancellation->exception, $failure);
            static::assertSame($callbacks, self::enabledCallbacks());

            $client->close();
            $server->close();
        } finally {
            $listener->close();
        }
    }

    /**
     * Fail a write waiting on a pending record with the given token, then check the record goes out first.
     */
    private function assertWriteFailsWithTheTokenAndKeepsTheRecord(ExpiringCancellationToken|InlineCancellationToken $cancellation): void
    {
        $calls = [];
        $sendable = false;
        [$inner, , $peer] = $this->createInner(static function (string $bytes) use (&$calls, &$sendable): int {
            $calls[] = $bytes;

            return $sendable ? strlen($bytes) : 0;
        });
        $stream = self::wrap($inner);

        // A short tryWrite() leaves its record pending, and the socket takes nothing.
        static::assertSame(3, $stream->tryWrite('AAA'));
        $callbacks = self::enabledCallbacks();

        $failure = null;
        try {
            $stream->write('BBB', $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            $failure = $e;
        }

        static::assertSame($cancellation->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        $sendable = true;
        self::drain($peer);
        static::assertSame(3, $stream->write('CCC', self::deadline()));
        static::assertSame(['AAA', 'CCC'], array_slice($calls, -2));
    }

    /**
     * Connect to the listener, complete a client handshake with the given server name, and close the stream once the
     * server has closed it.
     */
    private static function handshake(
        Network\ListenerInterface $listener,
        string $serverName,
        Async\CancellationTokenInterface $deadline,
    ): void {
        $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
        self::handshakeAndAwaitClose($plain, $serverName, $deadline);
    }

    /**
     * Complete a client handshake with the given server name, read until the server closes the connection, and close
     * the stream.
     *
     * The server closes once its handshake has completed. A client that closes first leaves the server's handshake
     * sending its TLS 1.3 session tickets to a closed connection, which OpenSSL ignores on Linux and macOS, but which
     * fails the handshake on Windows.
     */
    private static function handshakeAndAwaitClose(
        Network\StreamInterface $plain,
        string $serverName,
        Async\CancellationTokenInterface $deadline,
    ): void {
        $stream = self::connector()->connect($plain, $serverName, $deadline);

        try {
            static::assertSame('', $stream->readAll(cancellation: $deadline));
        } finally {
            $stream->close();
        }
    }

    /**
     * A plain stream mock over one end of a socket pair whose buffer is full, so that it is not writable.
     *
     * @param (callable(string): int) $tryWrite
     *
     * @return array{Network\StreamInterface&MockObject, resource, resource} The mock, its resource, and the peer end.
     */
    private function createInner(callable $tryWrite): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('Windows emulates socket pairs over TCP, and only for STREAM_PF_INET.');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        static::assertNotFalse($pair);
        [$resource, $peer] = $pair;
        $this->resources[] = $resource;
        $this->resources[] = $peer;
        stream_set_blocking($resource, false);
        stream_set_blocking($peer, false);
        self::fill($resource);

        $inner = $this->createMock(Network\StreamInterface::class);
        $inner->method('getLocalAddress')->willReturn(Network\Address::tcp());
        $inner->method('getPeerAddress')->willReturn(Network\Address::tcp());
        $inner->method('getStream')->willReturn($resource);
        $inner->method('tryWrite')->willReturnCallback($tryWrite);

        return [$inner, $resource, $peer];
    }

    /**
     * Fill the buffer of a socket pair end, so that it is not writable until its peer reads.
     *
     * @param resource $resource
     */
    private static function fill(mixed $resource): void
    {
        do {
            $written = @fwrite($resource, str_repeat('x', self::CHUNK_SIZE));
        } while (false !== $written && $written > 0);
    }

    /**
     * Read everything waiting at a socket pair end, so that its peer is writable again.
     *
     * @param resource $peer
     */
    private static function drain(mixed $peer): void
    {
        do {
            $read = @fread($peer, self::CHUNK_SIZE);
        } while (false !== $read && '' !== $read);
    }

    /**
     * Wrap a plain stream as a TLS stream.
     */
    private static function wrap(Network\StreamInterface $inner): TLS\StreamInterface
    {
        return new TLS\Internal\Stream(
            $inner,
            new TLS\ConnectionState(TLS\Version::Tls13, 'TLS_AES_256_GCM_SHA384', 256, 'TLSv1.3', null, null, null),
        );
    }

    /**
     * A client connector that trusts the fixture certificate without checking it.
     */
    private static function connector(): TLS\Connector
    {
        return new TLS\Connector(TLS\ClientConfiguration::default()->withPeerVerification(false));
    }

    /**
     * A server configuration with the fixture certificate.
     */
    private static function serverConfiguration(): TLS\ServerConfiguration
    {
        return TLS\ServerConfiguration::create(TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE));
    }

    /**
     * A token that ends a test that would otherwise hang.
     */
    private static function deadline(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(self::DEADLINE_SECONDS));
    }

    /**
     * The event loop callbacks that are enabled.
     *
     * @return list<string>
     */
    private static function enabledCallbacks(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), EventLoop::isEnabled(...)));
    }
}
