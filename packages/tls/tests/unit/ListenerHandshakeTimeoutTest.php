<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;

use function fclose;
use function fopen;

final class ListenerHandshakeTimeoutTest extends TestCase
{
    public function testAlreadyCancelledHandshakeClosesAcceptedStream(): void
    {
        $resource = fopen('php://memory', 'r+');
        $stream = $this->createMock(Network\StreamInterface::class);
        $stream->method('getStream')->willReturn($resource);
        $stream->expects(static::once())->method('close');
        $inner = $this->createStub(Network\ListenerInterface::class);
        $inner->method('accept')->willReturn($stream);
        $listener = new TLS\Listener($inner, $this->createServerConfiguration());
        $cancellation = new Async\SignalCancellationToken();
        $cancellation->cancel();

        try {
            $listener->accept($cancellation);
            static::fail('Expected caller cancellation.');
        } catch (Async\Exception\CancelledException $exception) {
            static::assertSame($cancellation, $exception->getToken());
        } finally {
            fclose($resource);
        }
    }

    public function testIdlePeerTimesOutAndNextPeerCanConnect(): void
    {
        $listener = $this->createListener(Duration::milliseconds(20));
        $port = $listener->getLocalAddress()->port;
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(2));
        $idle = TCP\connect('127.0.0.1', $port, cancellation: $cancellation);

        try {
            Async\concurrently([
                static function () use ($listener, $cancellation): void {
                    try {
                        $listener->accept($cancellation);
                        static::fail('Expected a handshake timeout.');
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertSame('TLS handshake timed out.', $exception->getMessage());
                        static::assertInstanceOf(Async\Exception\CancelledException::class, $exception->getPrevious());
                        static::assertInstanceOf(
                            Async\Exception\TimeoutException::class,
                            $exception->getPrevious()?->getPrevious(),
                        );
                    }

                    $stream = $listener->accept($cancellation);
                    $stream->writeAll('accepted', $cancellation);
                    $stream->close();
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
        $listener = $this->createListener(Duration::milliseconds(20));
        $port = $listener->getLocalAddress()->port;
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(2));

        try {
            Async\concurrently([
                static function () use ($listener, $cancellation): void {
                    $stream = $listener->accept($cancellation);
                    $stream->writeAll('accepted', $cancellation);
                    $stream->close();
                },
                static function () use ($port, $cancellation): void {
                    Async\sleep(Duration::milliseconds(50), $cancellation);
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

    public function testCallerCancellationClosesPeerAndKeepsToken(): void
    {
        $listener = $this->createListener(Duration::seconds(2));
        $port = $listener->getLocalAddress()->port;
        $idle = TCP\connect('127.0.0.1', $port);
        $cancellation = new Async\TimeoutCancellationToken(Duration::milliseconds(20));

        try {
            try {
                $listener->accept($cancellation);
                static::fail('Expected caller cancellation.');
            } catch (Async\Exception\CancelledException $exception) {
                static::assertSame($cancellation, $exception->getToken());
            }

            static::assertSame(
                '',
                $idle->readAll(cancellation: new Async\TimeoutCancellationToken(Duration::seconds(1))),
            );
        } finally {
            $idle->close();
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
