<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async\Exception\CancelledException;
use Psl\Async\SignalCancellationToken;
use Psl\H2\ClientConnection;
use Psl\H2\ConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ConnectionException;
use Psl\H2\Exception\RuntimeException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\H2\Tests\Fixture\TimeoutOnReadHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;
use Psl\IO;
use Revolt\EventLoop;

final class SendWindowWaiterTest extends TestCase
{
    /** @return iterable<string, array{string, positive-int}> */
    public static function provideStreamEnd(): iterable
    {
        yield 'local reset' => ['reset', 1];
        yield 'peer reset with connection credit' => ['peer-reset', 1];
        yield 'peer reset without enough connection credit' => ['peer-reset', 65_536];
        yield 'local DATA end' => ['data', 1];
        yield 'local HEADERS end' => ['headers', 1];
        yield 'stream error' => ['error', 1];
    }

    #[DataProvider('provideStreamEnd')]
    public function testStreamEndFailsWaitingSender(string $end, int $bytes): void
    {
        $input = match ($end) {
            'peer-reset' => Frame\encode(new RstStreamFrame(1, ErrorCode::Cancel)->toRaw()),
            'error' => Frame\encode(
                new HeadersFrame(1, new Encoder()->encode([new Header('x-test', 'value')]), false, true)->toRaw(),
            ),
            default => '',
        };
        $connection = $this->createClient($input);

        $this->assertWaitFails(
            $connection,
            static function () use ($connection, $end): void {
                switch ($end) {
                    case 'reset':
                        $connection->resetStream(1, ErrorCode::Cancel);
                        break;
                    case 'peer-reset':
                        $connection->readEvent();
                        break;
                    case 'data':
                        $connection->sendData(1, '', true);
                        break;
                    case 'headers':
                        $connection->sendHeaders(1, [], true);
                        break;
                    default:
                        try {
                            $connection->readEvent();
                            static::fail('Expected a malformed response to reset the stream.');
                        } catch (StreamException $exception) {
                            static::assertSame(ErrorCode::ProtocolError, $exception->errorCode);
                        }
                }
            },
            $bytes,
        );
    }

    public function testServerResponseEndFailsWaitingSender(): void
    {
        $input =
            Frame\encode(new SettingsFrame([Setting::InitialWindowSize->value => 0], false)->toRaw())
            . Frame\encode(
                new HeadersFrame(
                    1,
                    new Encoder()->encode([
                        new Header(':method', 'GET'),
                        new Header(':scheme', 'https'),
                        new Header(':path', '/'),
                    ]),
                    true,
                    true,
                )->toRaw(),
            );
        $connection = new ServerConnection(new ChunkedHandle($input));
        $connection->readEvent();
        $connection->readEvent();

        $this->assertWaitFails($connection, static function () use ($connection): void {
            $connection->sendHeadersWithStatus(1, '200', [], true);
        });
    }

    public function testResetOfLocalPushFailsWaitingSender(): void
    {
        $headers = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header(':authority', 'example.com'),
        ];
        $input =
            Frame\encode(new SettingsFrame([Setting::InitialWindowSize->value => 0], false)->toRaw())
            . Frame\encode(new HeadersFrame(1, new Encoder()->encode($headers), true, true)->toRaw());
        $connection = new ServerConnection(new ChunkedHandle($input));
        $connection->readEvent();
        $connection->readEvent();
        $connection->sendPushPromise(1, 2, $headers);
        $connection->sendHeadersWithStatus(2, '200', []);
        $this->assertWaitFails(
            $connection,
            static function () use ($connection): void {
                $connection->resetStream(2, ErrorCode::Cancel);
            },
            65_536,
            2,
        );
    }

    public function testClosedStreamRejectsNewWaitDespiteConnectionCredit(): void
    {
        $connection = $this->createClient();
        $connection->resetStream(1, ErrorCode::Cancel);

        $this->expectException(StreamException::class);
        $connection->waitForSendWindow(1, 1);
    }

    public function testResetDuringWriteFlushFailsBeforeWaiting(): void
    {
        $input = new ChunkedHandle(
            Frame\encode(new SettingsFrame([Setting::InitialWindowSize->value => 0], false)->toRaw()),
        );
        $handle = $this->createStubForIntersectionOfInterfaces([
            IO\ReadHandleInterface::class,
            IO\WriteHandleInterface::class,
        ]);
        $handle->method('read')->willReturnCallback($input->read(...));
        $connection = new ClientConnection($handle);
        $connection->readEvent();
        $connection->sendHeaders(1, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $reset = false;
        $handle
            ->method('writeAll')
            ->willReturnCallback(static function () use ($connection, &$reset): void {
                if (!$reset) {
                    $reset = true;
                    $connection->resetStream(1, ErrorCode::Cancel);
                }
            });

        $cancellation = new SignalCancellationToken();
        $timeout = EventLoop::delay(0.1, static function () use ($cancellation): void {
            $cancellation->cancel();
        });

        $this->expectException(StreamException::class);
        try {
            $connection->buffered(static function () use ($connection, $cancellation): void {
                $connection->ping('12345678');
                $connection->waitForSendWindow(1, 1, $cancellation);
            });
        } finally {
            EventLoop::cancel($timeout);
        }
    }

    public function testResetDoesNotFailConnectionWindowWaiter(): void
    {
        $connection = $this->createClient(Frame\encode(new WindowUpdateFrame(0, 1)->toRaw()));
        EventLoop::queue(static function () use ($connection): void {
            $connection->resetStream(1, ErrorCode::Cancel);
            $connection->readEvent();
        });

        $connection->waitForSendWindow(0, 65_536);
        static::assertSame(65_536, $connection->availableSendWindow(0));
    }

    /** @return iterable<string, array{string, non-negative-int, positive-int}> */
    public static function provideConnectionEnd(): iterable
    {
        foreach (['local', 'peer', 'protocol', 'eof', 'io'] as $end) {
            yield $end . ' stream waiter' => [$end, 1, 1];
            yield $end . ' connection waiter' => [$end, 0, 65_536];
        }
    }

    #[DataProvider('provideConnectionEnd')]
    public function testConnectionEndFailsWaitingSender(string $end, int $streamId, int $bytes): void
    {
        $input = match ($end) {
            'peer' => Frame\encode(new GoAwayFrame(0, ErrorCode::NoError->value, '')->toRaw()),
            'protocol' => Frame\encode(new RawFrame(6, 0, 1, '12345678')),
            default => '',
        };
        $connection = $this->createClient($input, $end === 'io');
        EventLoop::queue(static function () use ($connection, $end): void {
            if ($end === 'local') {
                $connection->goAway(ErrorCode::NoError);
                return;
            }

            try {
                $connection->readEvent();
            } catch (RuntimeException|IO\Exception\RuntimeException) {
                static::assertFalse($connection->isConnected());
            }
        });
        $cancellation = new SignalCancellationToken();
        $timeout = EventLoop::delay(0.1, static function () use ($cancellation): void {
            $cancellation->cancel();
        });

        $this->expectException(ConnectionException::class);
        try {
            $connection->waitForSendWindow($streamId, $bytes, $cancellation);
        } finally {
            EventLoop::cancel($timeout);
        }
    }

    public function testReadCancellationKeepsConnectionUsable(): void
    {
        $cancellation = new SignalCancellationToken();
        $handle = $this->createStubForIntersectionOfInterfaces([
            IO\ReadHandleInterface::class,
            IO\WriteHandleInterface::class,
        ]);
        $handle->method('read')->willThrowException(new CancelledException($cancellation));
        $connection = new ClientConnection($handle);

        try {
            $connection->readEvent($cancellation);
            static::fail('Expected the read to be cancelled.');
        } catch (CancelledException $error) {
            static::assertSame($cancellation, $error->getToken());
            static::assertTrue($connection->isConnected());
        }
    }

    private function createClient(string $input = '', bool $readFailure = false): ClientConnection
    {
        $input = Frame\encode(new SettingsFrame([Setting::InitialWindowSize->value => 0], false)->toRaw()) . $input;
        $connection = new ClientConnection(
            $readFailure ? new TimeoutOnReadHandle($input, 1) : new ChunkedHandle($input),
        );
        $connection->readEvent();
        $connection->sendHeaders(1, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        return $connection;
    }

    /**
     * @param Closure(): void $close
     * @param positive-int $bytes
     * @param positive-int $streamId
     */
    private function assertWaitFails(
        ConnectionInterface $connection,
        Closure $close,
        int $bytes = 1,
        int $streamId = 1,
    ): void {
        $cancellation = new SignalCancellationToken();
        $timeout = EventLoop::delay(0.1, static function () use ($cancellation): void {
            $cancellation->cancel();
        });
        EventLoop::queue($close);

        try {
            $connection->waitForSendWindow($streamId, $bytes, $cancellation);
            static::fail('Expected the closed stream to fail its waiting sender.');
        } catch (StreamException $exception) {
            static::assertSame($streamId, $exception->streamId);
            static::assertSame(ErrorCode::StreamClosed, $exception->errorCode);
        } finally {
            EventLoop::cancel($timeout);
        }
    }
}
