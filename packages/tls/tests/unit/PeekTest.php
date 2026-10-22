<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\TCP;
use Psl\TLS;

use function str_repeat;

/**
 * A TLS peek returns decrypted bytes without consuming them, and reads return those bytes first, in order.
 */
final class PeekTest extends TestCase
{
    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int DEADLINE_SECONDS = 5;

    /**
     * The length of the record the pending-plaintext test sends.
     */
    private const int RECORD_LENGTH = 100;

    /**
     * How long the server waits after its handshake before it writes, in milliseconds: long enough for its session
     * tickets to reach the client and wake a waiting peek first.
     */
    private const int WRITE_DELAY_MILLISECONDS = 100;

    /**
     * A peek returns the plaintext the peer sent, and the next reads return the same bytes, then the rest.
     */
    public function testPeekReturnsPlaintextThatReadsThenReturn(): void
    {
        [$client, $server] = self::connectPair();
        $deadline = self::deadline();

        try {
            $server->writeAll('hello world', $deadline);

            static::assertSame('hello', $client->peek(5, $deadline));
            static::assertSame('hello world', $client->peek(100, $deadline));
            static::assertFalse($client->reachedEndOfDataSource());
            static::assertSame('hello', $client->read(5, $deadline));
            static::assertSame(' world', $client->tryRead());

            $server->writeAll('again', $deadline);
            static::assertSame('again', $client->peek(5, $deadline));
            static::assertSame('again', $client->readFixedSize(5, $deadline));
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * A peek after the peer ended the data returns an empty string, as a peek on a plain stream does.
     */
    public function testPeekAtTheEndOfTheDataReturnsNothing(): void
    {
        [$client, $server] = self::connectPair();
        $deadline = self::deadline();

        try {
            $server->writeAll('last', $deadline);
            $server->close();

            static::assertSame('last', $client->peek(10, $deadline));
            static::assertSame('last', $client->readAll(cancellation: $deadline));
            static::assertSame('', $client->peek(10, $deadline));
            static::assertTrue($client->reachedEndOfDataSource());
        } finally {
            $client->close();
        }
    }

    /**
     * A read that waits while a peek on another fiber waits too returns the peeked bytes first, then its own.
     */
    public function testReadWaitingBehindAPeekReturnsThePeekedBytesFirst(): void
    {
        [$client, $server] = self::connectPair();
        $deadline = self::deadline();

        try {
            // The TLS 1.3 session tickets come first. A ticket that woke the peek would let the read wait first.
            self::consumeSessionTickets($client, $server, $deadline);

            $peek = Async\run(static fn(): string => $client->peek(100, $deadline));
            $read = Async\run(static fn(): string => $client->read(100, $deadline));
            Async\later();

            $server->writeAll('first', $deadline);
            static::assertSame('first', $peek->await());

            $server->writeAll('second', $deadline);
            static::assertSame('firstsecond', $read->await());
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * A peek that is woken by the server's TLS 1.3 session tickets, before any data has arrived, keeps waiting for the
     * data.
     *
     * OpenSSL takes the tickets, and the read under the peek returns nothing although the peer has not ended the data.
     * The client peeks while the server is still completing its handshake, so the peek waits before the tickets are
     * sent; the server writes once they have woken it.
     */
    public function testPeekWaitsPastSessionTicketsThatArriveBeforeTheData(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            [[$peeked, $ended, $read]] = Async\concurrently([
                static function () use ($listener, $deadline): array {
                    $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
                    $client = new TLS\Connector(
                        self::clientConfiguration()->withMinimumVersion(TLS\Version::Tls13),
                    )->connect($plain, 'localhost', $deadline);

                    try {
                        $peeked = $client->peek(5, $deadline);

                        return [$peeked, $client->reachedEndOfDataSource(), $client->readAll(cancellation: $deadline)];
                    } finally {
                        $client->close();
                    }
                },
                static function () use ($listener, $deadline): null {
                    $server = new TLS\Acceptor(self::serverConfiguration()->withSessionTickets(true))->accept(
                        $listener->accept($deadline),
                        $deadline,
                    );

                    try {
                        Async\sleep(Duration::milliseconds(self::WRITE_DELAY_MILLISECONDS));
                        $server->writeAll('hello', $deadline);
                    } finally {
                        $server->close();
                    }

                    return null;
                },
            ]);
        } finally {
            $listener->close();
        }

        static::assertSame('hello', $peeked);
        static::assertFalse($ended);
        static::assertSame('hello', $read);
    }

    /**
     * Closing the stream ends a waiting peek, and drops what an earlier peek kept.
     */
    public function testCloseEndsAWaitingPeek(): void
    {
        [$client, $server] = self::connectPair();
        $deadline = self::deadline();

        try {
            $peek = Async\run(static fn(): string => $client->peek(100, $deadline));
            Async\later();
            $client->close();

            $this->expectException(IO\Exception\AlreadyClosedException::class);

            $peek->await();
        } finally {
            $server->close();
        }
    }

    /**
     * Plaintext that OpenSSL decrypted and still holds, after a short read of its record, is read without waiting.
     */
    public function testPlaintextHeldByOpenSslIsReadWithoutWaiting(): void
    {
        [$client, $server] = self::connectPair();
        $deadline = self::deadline();

        try {
            $server->writeAll(str_repeat('x', self::RECORD_LENGTH), $deadline);
            // A read woken by the TLS 1.3 session tickets alone returns nothing; readFixedSize() reads past them.
            static::assertSame('x', $client->readFixedSize(1, $deadline));

            // The rest of the record is in OpenSSL, not in the socket. A token that cancels when anything subscribes
            // proves the read does not wait.
            static::assertSame(
                str_repeat('x', self::RECORD_LENGTH - 1),
                $client->read(self::RECORD_LENGTH, new InlineCancellationToken()),
            );
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * Connect a TLS client to a TLS server.
     *
     * @return array{TLS\StreamInterface, TLS\StreamInterface} The client, and the server.
     */
    private static function connectPair(): array
    {
        $listener = new TLS\Listener(TCP\listen('127.0.0.1', 0), self::serverConfiguration());
        $deadline = self::deadline();

        try {
            [$server, $client] = Async\concurrently([
                static fn(): TLS\StreamInterface => $listener->accept($deadline),
                static fn(): TLS\StreamInterface => TLS\connect(
                    '127.0.0.1',
                    $listener->getLocalAddress()->port,
                    self::clientConfiguration(),
                    $deadline,
                ),
            ]);

            return [$client, $server];
        } finally {
            $listener->close();
        }
    }

    /**
     * Read the TLS 1.3 session tickets the server sent after its handshake, by reading a byte the server sends after
     * them.
     */
    private static function consumeSessionTickets(
        TLS\StreamInterface $client,
        TLS\StreamInterface $server,
        Async\CancellationTokenInterface $cancellation,
    ): void {
        $server->writeAll('.', $cancellation);
        static::assertSame('.', $client->readFixedSize(1, $cancellation));
    }

    /**
     * The configuration of the test server.
     */
    private static function serverConfiguration(): TLS\ServerConfiguration
    {
        return TLS\ServerConfiguration::create(TLS\Certificate::create(
            __DIR__ . '/../../fixture/certs/server.crt',
            __DIR__ . '/../../fixture/certs/server.key',
        ));
    }

    /**
     * The configuration of the test client, which trusts the test server's self-signed certificate.
     */
    private static function clientConfiguration(): TLS\ClientConfiguration
    {
        return TLS\ClientConfiguration::default()->withPeerVerification(false);
    }

    /**
     * A token that ends a test that would otherwise hang.
     */
    private static function deadline(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(self::DEADLINE_SECONDS));
    }
}
