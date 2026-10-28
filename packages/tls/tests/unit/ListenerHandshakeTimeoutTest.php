<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;

final class ListenerHandshakeTimeoutTest extends TestCase
{
    /**
     * The handshake deadline of the listeners under test, in milliseconds.
     *
     * An idle peer reaches it, while a real handshake ends far within it even on a slow, shared CI runner: the client
     * and the server handshakes run in one event loop, so the deadline covers the work of both sides.
     */
    private const int HANDSHAKE_TIMEOUT_MILLISECONDS = 500;

    /**
     * How long the client waits before it connects, in milliseconds.
     *
     * Longer than the handshake deadline: a deadline started by the accept() call, rather than once the inner
     * listener has accepted the connection, would have passed before the client connects.
     */
    private const int CONNECT_DELAY_MILLISECONDS = 1_000;

    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int TEST_DEADLINE_SECONDS = 5;

    public function testAlreadyCancelledCallAcceptsNothing(): void
    {
        $inner = $this->createMock(Network\ListenerInterface::class);
        $inner->expects(static::never())->method('accept');
        $listener = new TLS\Listener($inner, $this->createServerConfiguration());
        $cancellation = new Async\SignalCancellationToken();
        $cancellation->cancel();

        try {
            $listener->accept($cancellation);
            static::fail('Expected caller cancellation.');
        } catch (Async\Exception\CancelledException $exception) {
            static::assertSame($cancellation, $exception->getToken());
        }
    }

    public function testIdlePeerTimesOutAndNextPeerCanConnect(): void
    {
        $listener = $this->createListener(Duration::milliseconds(self::HANDSHAKE_TIMEOUT_MILLISECONDS));
        $port = $listener->getLocalAddress()->port;
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(self::TEST_DEADLINE_SECONDS));
        $idle = TCP\connect('127.0.0.1', $port, cancellation: $cancellation);

        try {
            Async\concurrently([
                static function () use ($listener, $cancellation): void {
                    // Handshakes end in any order: the idle peer times out while the next peer's handshake runs.
                    $timedOut = false;
                    $accepted = false;
                    while (!$timedOut || !$accepted) {
                        try {
                            $stream = $listener->accept($cancellation);
                            $stream->writeAll('accepted', $cancellation);
                            $stream->close();
                            $accepted = true;
                        } catch (TLS\Exception\HandshakeFailedException $exception) {
                            static::assertSame('TLS handshake timed out.', $exception->getMessage());
                            static::assertInstanceOf(
                                Async\Exception\CancelledException::class,
                                $exception->getPrevious(),
                            );
                            static::assertInstanceOf(
                                Async\Exception\TimeoutException::class,
                                $exception->getPrevious()?->getPrevious(),
                            );
                            $timedOut = true;
                        }
                    }
                },
                static function () use ($idle, $cancellation): void {
                    static::assertSame('', $idle->readAll(cancellation: $cancellation));
                },
                static function () use ($port, $cancellation): void {
                    $stream = TLS\connect(
                        '127.0.0.1',
                        $port,
                        TLS\ClientConfiguration::default()->withPeerVerification(false),
                        $cancellation,
                    );

                    try {
                        static::assertSame('accepted', $stream->readAll(cancellation: $cancellation));
                    } finally {
                        $stream->close();
                    }
                },
            ]);
        } finally {
            $idle->close();
            $listener->close();
        }
    }

    public function testHandshakeTimeoutStartsAfterAcceptingTcpConnection(): void
    {
        $listener = $this->createListener(Duration::milliseconds(self::HANDSHAKE_TIMEOUT_MILLISECONDS));
        $port = $listener->getLocalAddress()->port;
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(self::TEST_DEADLINE_SECONDS));

        try {
            Async\concurrently([
                static function () use ($listener, $cancellation): void {
                    $stream = $listener->accept($cancellation);
                    $stream->writeAll('accepted', $cancellation);
                    $stream->close();
                },
                static function () use ($port, $cancellation): void {
                    Async\sleep(Duration::milliseconds(self::CONNECT_DELAY_MILLISECONDS), $cancellation);
                    $stream = TLS\connect(
                        '127.0.0.1',
                        $port,
                        TLS\ClientConfiguration::default()->withPeerVerification(false),
                        $cancellation,
                    );

                    try {
                        static::assertSame('accepted', $stream->readAll(cancellation: $cancellation));
                    } finally {
                        $stream->close();
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    private function createListener(Duration $handshakeTimeout): TLS\Listener
    {
        return new TLS\Listener(TCP\listen('127.0.0.1', 0), $this->createServerConfiguration(), $handshakeTimeout);
    }

    private function createServerConfiguration(): TLS\ServerConfiguration
    {
        return TLS\ServerConfiguration::create(new TLS\Certificate(
            __DIR__ . '/../../fixture/certs/server.crt',
            __DIR__ . '/../../fixture/certs/server.key',
        ));
    }
}
