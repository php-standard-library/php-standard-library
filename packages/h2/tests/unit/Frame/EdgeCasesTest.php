<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use Psl\Binary\Writer;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\RawFrame;

use function chr;
use function str_repeat;
use function strlen;

final class EdgeCasesTest extends TestCase
{
    public function testDecodeInsufficientData(): void
    {
        $this->expectException(FrameDecodingException::class);

        Frame\decode('short');
    }

    public function testDecodeInsufficientPayload(): void
    {
        $this->expectException(FrameDecodingException::class);

        $header = new Writer()
            ->u8(0)
            ->u16(100)
            ->u8(0)
            ->u8(0)
            ->u32(0)
            ->toString();
        Frame\decode($header);
    }

    public function testDataFrameWithPadding(): void
    {
        $padLength = 3;
        $payload = chr($padLength) . 'data' . str_repeat("\x00", $padLength);
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, $payload);
        $parsed = Frame\DataFrame::fromRaw($raw);

        static::assertSame('data', $parsed->data);
    }

    public function testDataFrameInvalidPadding(): void
    {
        $this->expectException(FrameDecodingException::class);

        $payload = chr(100) . 'x';
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, $payload);
        Frame\DataFrame::fromRaw($raw);
    }

    public function testPingFrameInvalidLength(): void
    {
        $this->expectException(ProtocolException::class);

        $raw = new RawFrame(FrameType::Ping->value, 0x00, 0, '1234');
        Frame\PingFrame::fromRaw($raw);
    }

    public function testWindowUpdateZeroIncrement(): void
    {
        $this->expectException(FrameDecodingException::class);

        $raw = new RawFrame(FrameType::WindowUpdate->value, 0x00, 1, new Writer()->u32(0)->toString());
        Frame\WindowUpdateFrame::fromRaw($raw);
    }

    public function testWindowUpdateInvalidLength(): void
    {
        $this->expectException(ProtocolException::class);

        $raw = new RawFrame(FrameType::WindowUpdate->value, 0x00, 1, 'xx');
        Frame\WindowUpdateFrame::fromRaw($raw);
    }

    public function testRstStreamInvalidLength(): void
    {
        $this->expectException(ProtocolException::class);

        $raw = new RawFrame(FrameType::RstStream->value, 0x00, 1, 'xx');
        Frame\RstStreamFrame::fromRaw($raw);
    }

    public function testSettingsAckWithPayload(): void
    {
        $this->expectException(ProtocolException::class);

        $raw = new RawFrame(FrameType::Settings->value, 0x01, 0, 'extra');
        Frame\SettingsFrame::fromRaw($raw);
    }

    public function testSettingsInvalidPayloadLength(): void
    {
        $this->expectException(ProtocolException::class);

        $raw = new RawFrame(FrameType::Settings->value, 0x00, 0, 'xyz');
        Frame\SettingsFrame::fromRaw($raw);
    }

    public function testPriorityFrameInvalidLength(): void
    {
        $this->expectException(StreamException::class);

        $raw = new RawFrame(FrameType::Priority->value, 0x00, 3, 'xx');
        Frame\PriorityFrame::fromRaw($raw);
    }

    public function testGoAwayFrameInvalidLength(): void
    {
        $this->expectException(ProtocolException::class);

        $raw = new RawFrame(FrameType::GoAway->value, 0x00, 0, 'short');
        Frame\GoAwayFrame::fromRaw($raw);
    }

    public function testLargePayload(): void
    {
        $data = str_repeat('x', 16_384);
        $frame = new Frame\DataFrame(1, $data, false)->toRaw();
        $encoded = Frame\encode($frame);
        [$decoded, $_] = Frame\decode($encoded);

        static::assertSame(16_384, strlen($decoded->payload));
    }

    public function testStreamIdReservedBitMasked(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x00, 0x8000_0001, 'x');
        $encoded = Frame\encode($raw);
        [$decoded, $_] = Frame\decode($encoded);

        static::assertSame(1, $decoded->streamId);
    }

    public function testDataFrameRejectsStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $raw = new RawFrame(FrameType::Data->value, 0x00, 0, 'payload');
        Frame\DataFrame::fromRaw($raw);
    }

    public function testHeadersFrameRejectsStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $raw = new RawFrame(FrameType::Headers->value, 0x04, 0, 'block');
        Frame\HeadersFrame::fromRaw($raw);
    }

    public function testPriorityFrameRejectsStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $payload = new Writer()->u32(5)->u8(127)->toString();
        $raw = new RawFrame(FrameType::Priority->value, 0x00, 0, $payload);
        Frame\PriorityFrame::fromRaw($raw);
    }

    public function testRstStreamFrameRejectsStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $payload = new Writer()->u32(0)->toString();
        $raw = new RawFrame(FrameType::RstStream->value, 0x00, 0, $payload);
        Frame\RstStreamFrame::fromRaw($raw);
    }

    public function testContinuationFrameRejectsStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $raw = new RawFrame(FrameType::Continuation->value, 0x04, 0, 'block');
        Frame\ContinuationFrame::fromRaw($raw);
    }

    public function testPushPromiseFrameRejectsStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $payload = new Writer()
            ->u32(2)
            ->bytes('headers')
            ->toString();
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 0, $payload);
        Frame\PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromiseFrameRejectsPromisedStreamIdZero(): void
    {
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('Stream ID required');

        $payload = new Writer()
            ->u32(0)
            ->bytes('headers')
            ->toString();
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 1, $payload);
        Frame\PushPromiseFrame::fromRaw($raw);
    }

    public function testSettingsFrameSkipsZeroId(): void
    {
        $payload = new Writer()
            ->u16(0)
            ->u32(999)
            ->u16(0x3)
            ->u32(100)
            ->toString();
        $raw = new RawFrame(FrameType::Settings->value, 0x00, 0, $payload);
        $parsed = Frame\SettingsFrame::fromRaw($raw);
        static::assertArrayNotHasKey(0, $parsed->settings);
        static::assertSame(100, $parsed->settings[0x3]);
    }

    public function testGoAwayFrameAcceptsLastStreamIdZero(): void
    {
        $payload = new Writer()
            ->u32(0)
            ->u32(0)
            ->toString();
        $raw = new RawFrame(FrameType::GoAway->value, 0x00, 0, $payload);
        $parsed = Frame\GoAwayFrame::fromRaw($raw);
        static::assertSame(0, $parsed->lastStreamId);
    }

    public function testDecodeAtOffset(): void
    {
        $prefix = 'garbage';
        $frame = new Frame\PingFrame('12345678', false)->toRaw();
        $data = $prefix . Frame\encode($frame);

        [$decoded, $_] = Frame\decode($data, strlen($prefix));

        static::assertSame(FrameType::Ping->value, $decoded->type);
    }
}
