<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Revolt\EventLoop;
use WeakReference;

use function array_diff;
use function array_values;
use function gc_collect_cycles;
use function hrtime;

/**
 * `TLS\Listener::accept()` runs the handshakes of accepted connections concurrently, so one peer that connects and
 * then stays silent no longer holds up every later connection until its handshake deadline passes.
 */
final class ListenerConcurrentHandshakeTest extends TestCase
{
    /**
     * The deadline of a test, in seconds: long enough for every handshake of a test to end on a loaded machine.
     */
    private const int TEST_TIMEOUT_SECONDS = 5;

    private const string SERVER_CERTIFICATE = __DIR__ . '/../../fixture/certs/server.crt';

    private const string SERVER_KEY = __DIR__ . '/../../fixture/certs/server.key';

    public function testSilentPeerNoLongerBlocksLaterPeers(): void
    {
        $listener = $this->createListener(Duration::seconds(2));
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $silent = TCP\connect('127.0.0.1', $port, cancellation: $deadline);

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $deadline): void {
                    $start = hrtime(true);
                    $stream = $listener->accept($deadline);
                    static::assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'The silent peer held up the next one.');
                    $stream->writeAll('accepted', $deadline);
                    $stream->close();

                    try {
                        $listener->accept($deadline);
                        static::fail('Expected the silent peer to time out.');
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertSame('TLS handshake timed out.', $exception->getMessage());
                    }
                },
                'client' => static function () use ($port, $deadline): void {
                    Async\sleep(Duration::milliseconds(50), $deadline);
                    $stream = self::connect($port, $deadline);

                    try {
                        static::assertSame('accepted', $stream->readAll(cancellation: $deadline));
                    } finally {
                        $stream->close();
                    }
                },
                'silent' => static function () use ($silent, $deadline): void {
                    static::assertSame('', $silent->readAll(cancellation: $deadline));
                },
            ]);
        } finally {
            $silent->close();
            $listener->close();
        }
    }

    public function testPendingLimitStopsTakingConnectionsUntilAHandshakeEnds(): void
    {
        $inner = new CountingListener(TCP\listen('127.0.0.1', 0));
        $listener = new TLS\Listener(
            $inner,
            self::serverConfiguration(),
            Duration::milliseconds(300),
            maxPendingHandshakes: 1,
        );
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $silent = TCP\connect('127.0.0.1', $port, cancellation: $deadline);

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $inner, $deadline): void {
                    try {
                        $listener->accept($deadline);
                        static::fail('Expected the silent peer to time out.');
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertSame('TLS handshake timed out.', $exception->getMessage());
                    }

                    $stream = $listener->accept($deadline);
                    static::assertSame(2, $inner->accepted);
                    $stream->writeAll('accepted', $deadline);
                    $stream->close();
                },
                'client' => static function () use ($port, $inner, $deadline): void {
                    // The silent peer holds the only slot: the client's connection sits in the inner listener.
                    Async\sleep(Duration::milliseconds(150), $deadline);
                    static::assertSame(1, $inner->accepted);

                    $start = hrtime(true);
                    $stream = self::connect($port, $deadline);
                    static::assertGreaterThan(
                        0.1,
                        (hrtime(true) - $start) / 1e9,
                        'The handshake ran before a slot was free.',
                    );
                    static::assertSame(2, $inner->accepted);

                    try {
                        static::assertSame('accepted', $stream->readAll(cancellation: $deadline));
                    } finally {
                        $stream->close();
                    }
                },
                'silent' => static function () use ($silent, $deadline): void {
                    static::assertSame('', $silent->readAll(cancellation: $deadline));
                },
            ]);
        } finally {
            $silent->close();
            $listener->close();
        }
    }

    public function testFailedHandshakeIsReportedOnceAndLaterPeersAreAccepted(): void
    {
        $listener = $this->createListener(Duration::seconds(2));
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $plain = TCP\connect('127.0.0.1', $port, cancellation: $deadline);
        $plain->writeAll("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n", $deadline);

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $deadline): void {
                    try {
                        $listener->accept($deadline);
                        static::fail('Expected the plain-text peer to fail its handshake.');
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertNotSame('TLS handshake timed out.', $exception->getMessage());
                        static::assertNotInstanceOf(
                            Async\Exception\CancelledException::class,
                            $exception->getPrevious(),
                        );
                    }

                    $stream = $listener->accept($deadline);
                    $stream->writeAll('accepted', $deadline);
                    $stream->close();
                },
                'client' => static function () use ($port, $deadline): void {
                    Async\sleep(Duration::milliseconds(50), $deadline);
                    $stream = self::connect($port, $deadline);

                    try {
                        static::assertSame('accepted', $stream->readAll(cancellation: $deadline));
                    } finally {
                        $stream->close();
                    }
                },
                'plain' => static function () use ($plain, $deadline): void {
                    static::assertSame('', $plain->readAll(cancellation: $deadline));
                },
            ]);
        } finally {
            $plain->close();
            $listener->close();
        }
    }

    public function testCancelledCallKeepsItsTokenAndLeavesTheHandshakeToItsDeadline(): void
    {
        $listener = $this->createListener(Duration::milliseconds(300));
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $silent = TCP\connect('127.0.0.1', $port, cancellation: $deadline);
        $cancellation = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

        try {
            try {
                $listener->accept($cancellation);
                static::fail('Expected the caller cancellation.');
            } catch (Async\Exception\CancelledException $exception) {
                static::assertSame($cancellation, $exception->getToken());
            }

            // The silent peer's handshake is still pending: its connection stays open until its own deadline.
            try {
                $silent->readAll(cancellation: new Async\TimeoutCancellationToken(Duration::milliseconds(50)));
                static::fail('Expected the silent peer to still be connected.');
            } catch (Async\Exception\CancelledException) {
                $this->addToAssertionCount(1);
            }

            try {
                $listener->accept($deadline);
                static::fail('Expected the silent peer to time out.');
            } catch (TLS\Exception\HandshakeFailedException $exception) {
                static::assertSame('TLS handshake timed out.', $exception->getMessage());
            }

            static::assertSame('', $silent->readAll(cancellation: $deadline));
        } finally {
            $silent->close();
            $listener->close();
        }
    }

    public function testCancelledCallTakesNoOutcomeFromTheCallsStillWaiting(): void
    {
        $listener = $this->createListener(Duration::seconds(2));
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $cancellation = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

        try {
            Async\concurrently([
                'cancelled' => static function () use ($listener, $cancellation): void {
                    try {
                        $listener->accept($cancellation);
                        static::fail('Expected the caller cancellation.');
                    } catch (Async\Exception\CancelledException $exception) {
                        static::assertSame($cancellation, $exception->getToken());
                    }
                },
                'waiting' => static function () use ($listener, $deadline): void {
                    $stream = $listener->accept($deadline);
                    $stream->writeAll('accepted', $deadline);
                    $stream->close();
                },
                'client' => static function () use ($port, $deadline): void {
                    Async\sleep(Duration::milliseconds(150), $deadline);
                    $stream = self::connect($port, $deadline);

                    try {
                        static::assertSame('accepted', $stream->readAll(cancellation: $deadline));
                    } finally {
                        $stream->close();
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    public function testCloseCancelsPendingHandshakesAndSettlesWaitingCalls(): void
    {
        $inner = TCP\listen('127.0.0.1', 0);
        $listener = new TLS\Listener($inner, self::serverConfiguration(), Duration::seconds(5));
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $first = TCP\connect('127.0.0.1', $port, cancellation: $deadline);
        $second = TCP\connect('127.0.0.1', $port, cancellation: $deadline);

        try {
            Async\concurrently([
                'waiting' => function () use ($listener, $deadline): void {
                    try {
                        $listener->accept($deadline);
                        static::fail('Expected the close to end the wait.');
                    } catch (Network\Exception\AlreadyStoppedException) {
                        $this->addToAssertionCount(1);
                    }
                },
                'closer' => function () use ($listener, $inner, $deadline): void {
                    // Let the listener take both connections and start their handshakes.
                    Async\sleep(Duration::milliseconds(100), $deadline);
                    $listener->close();

                    static::assertTrue($listener->isClosed());
                    static::assertTrue($inner->isClosed());

                    try {
                        $listener->accept($deadline);
                        static::fail('Expected a closed listener to accept nothing.');
                    } catch (Network\Exception\AlreadyStoppedException) {
                        $this->addToAssertionCount(1);
                    }
                },
                'peers' => static function () use ($first, $second, $deadline): void {
                    static::assertSame('', $first->readAll(cancellation: $deadline));
                    static::assertSame('', $second->readAll(cancellation: $deadline));
                },
            ]);
        } finally {
            $first->close();
            $second->close();
            $listener->close();
        }
    }

    public function testCloseClosesConnectionsThatCompletedForNobody(): void
    {
        $listener = $this->createListener(Duration::seconds(2));
        $port = $listener->getLocalAddress()->port;
        $deadline = self::deadline();
        $late = TCP\connect('127.0.0.1', $port, cancellation: $deadline);

        try {
            Async\concurrently([
                'server' => function () use ($listener, $deadline): void {
                    // The call starts the accepting, and with it the late peer's handshake, then leaves: the
                    // handshake runs on, and the connection it completes waits for nobody until close() closes it.
                    try {
                        $listener->accept(new Async\TimeoutCancellationToken(Duration::milliseconds(50)));
                        static::fail('Expected the caller cancellation.');
                    } catch (Async\Exception\CancelledException) {
                        $this->addToAssertionCount(1);
                    }

                    Async\sleep(Duration::milliseconds(400), $deadline);
                    $listener->close();
                },
                'late' => static function () use ($late, $deadline): void {
                    // The ClientHello goes out only once the waiting call has left.
                    Async\sleep(Duration::milliseconds(150), $deadline);
                    $connector = new TLS\Connector(TLS\ClientConfiguration::default()->withPeerVerification(false));
                    $stream = $connector->connect($late, cancellation: $deadline);

                    try {
                        static::assertSame('', $stream->readAll(cancellation: $deadline));
                    } finally {
                        $stream->close();
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    public function testLeavesNoWatcherNorFiberBehind(): void
    {
        $deadline = self::deadline();
        $before = EventLoop::getIdentifiers();

        $listener = $this->createListener(Duration::milliseconds(100));
        $weak = WeakReference::create($listener);
        $port = $listener->getLocalAddress()->port;
        $silent = TCP\connect('127.0.0.1', $port, cancellation: $deadline);
        $pending = TCP\connect('127.0.0.1', $port, cancellation: $deadline);

        Async\concurrently([
            'server' => function () use ($listener, $deadline): void {
                $outcomes = 0;
                while ($outcomes < 2) {
                    try {
                        $stream = $listener->accept($deadline);
                        $stream->writeAll('accepted', $deadline);
                        $stream->close();
                    } catch (TLS\Exception\HandshakeFailedException) {
                        $this->addToAssertionCount(1);
                    }

                    $outcomes++;
                }
            },
            'client' => static function () use ($port, $deadline): void {
                $stream = self::connect($port, $deadline);

                try {
                    static::assertSame('accepted', $stream->readAll(cancellation: $deadline));
                } finally {
                    $stream->close();
                }
            },
            'silent' => static function () use ($silent, $deadline): void {
                static::assertSame('', $silent->readAll(cancellation: $deadline));
            },
        ]);

        // A handshake is still pending when the listener closes: close() cancels it.
        $listener->close();
        static::assertSame('', $pending->readAll(cancellation: $deadline));
        $silent->close();
        $pending->close();
        unset($listener);

        Async\sleep(Duration::milliseconds(20), $deadline);
        gc_collect_cycles();

        static::assertNull($weak->get(), 'The listener is still referenced.');
        static::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $before)));
    }

    private function createListener(Duration $handshakeTimeout): TLS\Listener
    {
        return new TLS\Listener(TCP\listen('127.0.0.1', 0), self::serverConfiguration(), $handshakeTimeout);
    }

    private static function serverConfiguration(): TLS\ServerConfiguration
    {
        return TLS\ServerConfiguration::create(new TLS\Certificate(self::SERVER_CERTIFICATE, self::SERVER_KEY));
    }

    private static function deadline(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(self::TEST_TIMEOUT_SECONDS));
    }

    /**
     * @param int<0, 65535> $port
     */
    private static function connect(int $port, Async\CancellationTokenInterface $cancellation): TLS\StreamInterface
    {
        return TLS\connect(
            '127.0.0.1',
            $port,
            TLS\ClientConfiguration::default()->withPeerVerification(false),
            $cancellation,
        );
    }
}
