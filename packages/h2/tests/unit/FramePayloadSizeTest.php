<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\FrameInterface;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Header;

use function strlen;
use function substr;

final class FramePayloadSizeTest extends TestCase
{
    /** @return iterable<string, array{class-string<FrameInterface>, RawFrame}> */
    public static function provideInvalidSizes(): iterable
    {
        yield 'empty PING' => [Frame\PingFrame::class, new RawFrame(6, 0, 0, '')];
        yield 'short PING' => [Frame\PingFrame::class, new RawFrame(6, 0, 0, '1234567')];
        yield 'long PING' => [Frame\PingFrame::class, new RawFrame(6, 0, 0, '123456789')];
        yield 'SETTINGS partial pair' => [Frame\SettingsFrame::class, new RawFrame(4, 0, 0, 'x')];
        yield 'SETTINGS ACK payload' => [Frame\SettingsFrame::class, new RawFrame(4, 1, 0, '123456')];
        yield 'RST_STREAM' => [Frame\RstStreamFrame::class, new RawFrame(3, 0, 1, '123')];
        yield 'connection WINDOW_UPDATE' => [Frame\WindowUpdateFrame::class, new RawFrame(8, 0, 0, '123')];
        yield 'stream WINDOW_UPDATE' => [Frame\WindowUpdateFrame::class, new RawFrame(8, 0, 1, '123')];
        yield 'short PRIORITY' => [Frame\PriorityFrame::class, new RawFrame(2, 0, 1, '1234')];
        yield 'long PRIORITY' => [Frame\PriorityFrame::class, new RawFrame(2, 0, 1, '123456')];
        yield 'GOAWAY' => [Frame\GoAwayFrame::class, new RawFrame(7, 0, 0, '1234567')];
        yield 'DATA pad length' => [Frame\DataFrame::class, new RawFrame(0, 8, 1, '')];
        yield 'HEADERS pad length' => [Frame\HeadersFrame::class, new RawFrame(1, 12, 1, '')];
        yield 'HEADERS priority' => [Frame\HeadersFrame::class, new RawFrame(1, 36, 1, '1234')];
        yield 'padded HEADERS priority' => [Frame\HeadersFrame::class, new RawFrame(1, 44, 1, "\0abcd")];
        yield 'PUSH_PROMISE stream ID' => [Frame\PushPromiseFrame::class, new RawFrame(5, 4, 1, '123')];
        yield 'PUSH_PROMISE pad length' => [Frame\PushPromiseFrame::class, new RawFrame(5, 12, 1, '')];
        yield 'padded PUSH_PROMISE stream ID' => [Frame\PushPromiseFrame::class, new RawFrame(5, 12, 1, "\0abc")];
    }

    /** @param class-string<FrameInterface> $type */
    #[DataProvider('provideInvalidSizes')]
    public function testDecoderReportsFrameSizeError(string $type, RawFrame $frame): void
    {
        try {
            $type::fromRaw($frame);
            static::fail('Expected an invalid frame size.');
        } catch (ProtocolException|StreamException $error) {
            static::assertSame(ErrorCode::FrameSizeError, $error->errorCode);
            static::assertSame($frame->type === 2, $error instanceof StreamException);
        }
    }

    /** @param class-string<FrameInterface> $_type */
    #[DataProvider('provideInvalidSizes')]
    public function testConnectionSendsFrameSizeError(string $_type, RawFrame $frame): void
    {
        $handle = new ChunkedHandle(Frame\encode($frame));
        $connection = new ClientConnection($handle);
        $connection->sendHeaders(1, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $offset = strlen($handle->written);

        try {
            $connection->readEvent();
            static::fail('Expected an invalid frame size.');
        } catch (ProtocolException|StreamException $error) {
            static::assertSame(ErrorCode::FrameSizeError, $error->errorCode);
        }

        [$response] = Frame\decode(substr($handle->written, $offset));
        if ($frame->type === 2) {
            static::assertSame(ErrorCode::FrameSizeError, Frame\RstStreamFrame::fromRaw($response)->errorCode);
            static::assertSame(1, $response->streamId);
            static::assertTrue($connection->isConnected());
        } else {
            static::assertSame(ErrorCode::FrameSizeError->value, Frame\GoAwayFrame::fromRaw($response)->errorCode);
            static::assertFalse($connection->isConnected());
        }
    }

    public function testMalformedIdlePriorityClosesConnectionWithoutResettingIdleStream(): void
    {
        $handle = new ChunkedHandle(Frame\encode(new RawFrame(2, 0, 1, '1234')));
        $connection = new ClientConnection($handle);
        try {
            $connection->readEvent();
            static::fail('Expected an invalid frame size.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::FrameSizeError, $error->errorCode);
        }

        [$response] = Frame\decode($handle->written);
        static::assertSame(7, $response->type);
        static::assertSame(ErrorCode::FrameSizeError->value, Frame\GoAwayFrame::fromRaw($response)->errorCode);
    }

    /** @return iterable<string, array{RawFrame}> */
    public static function provideExcessPadding(): iterable
    {
        yield 'DATA' => [new RawFrame(0, 8, 1, "\x01")];
        yield 'HEADERS' => [new RawFrame(1, 12, 1, "\x01")];
        yield 'HEADERS with priority' => [new RawFrame(1, 44, 1, "\x06\0\0\0\0\0")];
        yield 'PUSH_PROMISE' => [new RawFrame(5, 12, 1, "\x06\0\0\0\x02")];
    }

    #[DataProvider('provideExcessPadding')]
    public function testExcessPaddingRemainsProtocolError(RawFrame $frame): void
    {
        $handle = new ChunkedHandle(Frame\encode($frame));
        $connection = new ClientConnection($handle);
        try {
            $connection->readEvent();
            static::fail('Expected invalid padding.');
        } catch (FrameDecodingException) {
            [$response] = Frame\decode($handle->written);
            static::assertSame(ErrorCode::ProtocolError->value, Frame\GoAwayFrame::fromRaw($response)->errorCode);
        }
    }
}
