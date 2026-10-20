<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\AltSvcFrame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\OriginFrame;
use Psl\H2\Frame\PingFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;

use function chr;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function substr;
use function unpack;

final class MutationCoverageTest extends TestCase
{
    public function testAltSvcFrameRoundTripExactBytes(): void
    {
        $frame = new AltSvcFrame(0, 'https://example.com', 'h3=":443"');
        $raw = $frame->toRaw();

        static::assertSame(FrameType::AltSvc->value, $raw->type);
        static::assertSame(0, $raw->flags);
        static::assertSame(0, $raw->streamId);

        $originLength = unpack('n', $raw->payload, 0)[1];
        static::assertSame(strlen('https://example.com'), $originLength);
        static::assertSame('https://example.com', substr($raw->payload, 2, $originLength));
        static::assertSame('h3=":443"', substr($raw->payload, 2 + $originLength));

        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame(0, $parsed->streamId);
        static::assertSame('https://example.com', $parsed->origin);
        static::assertSame('h3=":443"', $parsed->fieldValue);
    }

    public function testAltSvcFramePayloadMinimum2BytesCheck(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, "\x00");
        $this->expectException(FrameDecodingException::class);
        AltSvcFrame::fromRaw($raw);
    }

    public function testAltSvcFramePayloadExactly2BytesSucceeds(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 0));
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('', $parsed->origin);
        static::assertSame('', $parsed->fieldValue);
    }

    public function testAltSvcFrameEmptyPayloadFails(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, '');
        $this->expectException(FrameDecodingException::class);
        AltSvcFrame::fromRaw($raw);
    }

    public function testAltSvcFrameOriginLengthValidation(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 10) . 'abc');
        $this->expectException(FrameDecodingException::class);
        AltSvcFrame::fromRaw($raw);
    }

    public function testAltSvcFrameOriginLengthExactBoundary(): void
    {
        $origin = 'example.com';
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', strlen($origin)) . $origin);
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame($origin, $parsed->origin);
        static::assertSame('', $parsed->fieldValue);
    }

    public function testAltSvcFrameWithEmptyOrigin(): void
    {
        $frame = new AltSvcFrame(1, '', 'h2=":8000"');
        $raw = $frame->toRaw();

        $originLength = unpack('n', $raw->payload, 0)[1];
        static::assertSame(0, $originLength);

        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('', $parsed->origin);
        static::assertSame('h2=":8000"', $parsed->fieldValue);
        static::assertSame(1, $parsed->streamId);
    }

    public function testAltSvcFrameFlagsByteIsZero(): void
    {
        $frame = new AltSvcFrame(0, 'origin', 'value');
        $raw = $frame->toRaw();
        static::assertSame(0, $raw->flags);
        static::assertTrue(0 === $raw->flags);
    }

    public function testAltSvcFrameOnNonZeroStream(): void
    {
        $frame = new AltSvcFrame(5, '', 'h3=":443"');
        $raw = $frame->toRaw();
        static::assertSame(5, $raw->streamId);

        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame(5, $parsed->streamId);
        static::assertSame('', $parsed->origin);
    }

    public function testDataFramePaddedFlagParsingMinimum(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, '');
        $this->expectException(ProtocolException::class);
        DataFrame::fromRaw($raw);
    }

    public function testDataFramePaddedWithExactly1Byte(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, "\x00");
        $parsed = DataFrame::fromRaw($raw);
        static::assertSame('', $parsed->data);
    }

    public function testDataFramePadLengthEqualsPayloadLength(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(5));
        $this->expectException(FrameDecodingException::class);
        DataFrame::fromRaw($raw);
    }

    public function testDataFramePadLengthExactlyPayloadLengthMinus1(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(0));
        $parsed = DataFrame::fromRaw($raw);
        static::assertSame('', $parsed->data);
    }

    public function testDataFrameDataLengthNonNegative(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(3) . 'ab');
        $this->expectException(FrameDecodingException::class);
        DataFrame::fromRaw($raw);
    }

    public function testDataFrameExactBytesRoundTrip(): void
    {
        $frame = new DataFrame(1, 'hello', true);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::Data->value, $raw->type);
        static::assertSame(0x01, $raw->flags);
        static::assertSame(1, $raw->streamId);
        static::assertSame('hello', $raw->payload);

        $parsed = DataFrame::fromRaw($raw);
        static::assertSame(1, $parsed->streamId);
        static::assertSame('hello', $parsed->data);
        static::assertTrue($parsed->endStream);
    }

    public function testDataFrameNoEndStreamExactFlags(): void
    {
        $frame = new DataFrame(1, 'data', false);
        $raw = $frame->toRaw();
        static::assertSame(0x00, $raw->flags);

        $parsed = DataFrame::fromRaw($raw);
        static::assertFalse($parsed->endStream);
    }

    public function testDataFramePaddedRoundTrip(): void
    {
        $padLength = 2;
        $payload = chr($padLength) . 'test' . str_repeat("\x00", $padLength);
        $raw = new RawFrame(FrameType::Data->value, 0x09, 1, $payload);

        $parsed = DataFrame::fromRaw($raw);
        static::assertSame('test', $parsed->data);
        static::assertTrue($parsed->endStream);
    }

    public function testGoAwayFrameStreamIdIsZero(): void
    {
        $frame = new GoAwayFrame(7, ErrorCode::NoError->value, 'debug');
        static::assertSame(0, $frame->streamId);

        $raw = $frame->toRaw();
        static::assertSame(0, $raw->streamId);
    }

    public function testGoAwayFrameDebugDataExactly8BytesPayload(): void
    {
        $frame = new GoAwayFrame(1, 0, '');
        $raw = $frame->toRaw();
        static::assertSame(8, strlen($raw->payload));

        $parsed = GoAwayFrame::fromRaw($raw);
        static::assertSame('', $parsed->debugData);
        static::assertSame(1, $parsed->lastStreamId);
        static::assertSame(0, $parsed->errorCode);
    }

    public function testGoAwayFrameDebugDataWith9BytesPayload(): void
    {
        $frame = new GoAwayFrame(1, 0, 'x');
        $raw = $frame->toRaw();
        static::assertSame(9, strlen($raw->payload));

        $parsed = GoAwayFrame::fromRaw($raw);
        static::assertSame('x', $parsed->debugData);
    }

    public function testGoAwayFramePayloadLessThan8BytesFails(): void
    {
        $raw = new RawFrame(FrameType::GoAway->value, 0, 0, str_repeat("\x00", 7));
        $this->expectException(ProtocolException::class);
        GoAwayFrame::fromRaw($raw);
    }

    public function testGoAwayFramePayloadExactly8BytesSucceeds(): void
    {
        $raw = new RawFrame(FrameType::GoAway->value, 0, 0, pack('NN', 0, 0));
        $parsed = GoAwayFrame::fromRaw($raw);
        static::assertSame(0, $parsed->lastStreamId);
        static::assertSame(0, $parsed->errorCode);
        static::assertSame('', $parsed->debugData);
    }

    public function testGoAwayFrameFlagsByteIsZero(): void
    {
        $frame = new GoAwayFrame(0, 0, '');
        $raw = $frame->toRaw();
        static::assertSame(0, $raw->flags);
    }

    public function testGoAwayFrameExactBytesRoundTrip(): void
    {
        $frame = new GoAwayFrame(100, ErrorCode::FlowControlError->value, 'oops');
        $raw = $frame->toRaw();

        static::assertSame(FrameType::GoAway->value, $raw->type);
        static::assertSame(0, $raw->flags);
        static::assertSame(0, $raw->streamId);

        $lastStreamId = unpack('N', $raw->payload, 0)[1] & 0x7FFF_FFFF;
        $errorCode = unpack('N', $raw->payload, 4)[1];
        $debugData = substr($raw->payload, 8);

        static::assertSame(100, $lastStreamId);
        static::assertSame(ErrorCode::FlowControlError->value, $errorCode);
        static::assertSame('oops', $debugData);

        $parsed = GoAwayFrame::fromRaw($raw);
        static::assertSame(100, $parsed->lastStreamId);
        static::assertSame(ErrorCode::FlowControlError->value, $parsed->errorCode);
        static::assertSame('oops', $parsed->debugData);
    }

    public function testHeadersFrameExclusiveDefaultIsFalse(): void
    {
        $frame = new HeadersFrame(1, 'block', false, true);
        static::assertFalse($frame->exclusive);

        $raw = $frame->toRaw();
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertFalse($parsed->exclusive);
    }

    public function testHeadersFramePaddedFlagParsing(): void
    {
        $padLength = 2;
        $payload = chr($padLength) . 'hdr' . str_repeat("\x00", $padLength);
        $raw = new RawFrame(FrameType::Headers->value, 0x0C, 1, $payload);

        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame('hdr', $parsed->headerBlockFragment);
        static::assertTrue($parsed->endHeaders);
        static::assertFalse($parsed->endStream);
    }

    public function testHeadersFramePaddedEmptyPayloadFails(): void
    {
        $raw = new RawFrame(FrameType::Headers->value, 0x08, 1, '');
        $this->expectException(ProtocolException::class);
        HeadersFrame::fromRaw($raw);
    }

    public function testHeadersFramePriorityParsing(): void
    {
        $depValue = 5 | 0x8000_0000;
        $payload = pack('NC', $depValue, 128 - 1) . 'hdr-block';
        $raw = new RawFrame(FrameType::Headers->value, 0x24, 1, $payload);

        $parsed = HeadersFrame::fromRaw($raw);
        static::assertTrue($parsed->exclusive);
        static::assertSame(5, $parsed->streamDependency);
        static::assertSame(128, $parsed->weight);
        static::assertSame('hdr-block', $parsed->headerBlockFragment);
    }

    public function testHeadersFramePriorityNotExclusive(): void
    {
        $depValue = 5;
        $payload = pack('NC', $depValue, 63) . 'hdr';
        $raw = new RawFrame(FrameType::Headers->value, 0x24, 1, $payload);

        $parsed = HeadersFrame::fromRaw($raw);
        static::assertFalse($parsed->exclusive);
        static::assertSame(5, $parsed->streamDependency);
        static::assertSame(64, $parsed->weight);
    }

    public function testHeadersFramePriorityWeightEncoding(): void
    {
        $frame1 = new HeadersFrame(1, 'h', false, true, 0, 1, false);
        $raw1 = $frame1->toRaw();
        static::assertSame(0, ord($raw1->payload[4]));

        $frame256 = new HeadersFrame(1, 'h', false, true, 0, 256, false);
        $raw256 = $frame256->toRaw();
        static::assertSame(255, ord($raw256->payload[4]));
    }

    public function testHeadersFramePriorityOffsetCalculation(): void
    {
        $padLength = 1;
        $depValue = 3;
        $payload = chr($padLength) . pack('NC', $depValue, 15) . 'block' . "\x00";
        $raw = new RawFrame(FrameType::Headers->value, 0x2C, 1, $payload);

        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame(3, $parsed->streamDependency);
        static::assertSame(16, $parsed->weight);
        static::assertSame('block', $parsed->headerBlockFragment);
    }

    public function testHeadersFramePriorityInsufficientDataFails(): void
    {
        $raw = new RawFrame(FrameType::Headers->value, 0x20, 1, 'abc');
        $this->expectException(ProtocolException::class);
        HeadersFrame::fromRaw($raw);
    }

    public function testHeadersFrameFlagsEncoding(): void
    {
        $frame = new HeadersFrame(1, 'h', true, true);
        $raw = $frame->toRaw();
        static::assertSame(0x05, $raw->flags);

        $frame2 = new HeadersFrame(1, 'h', false, true);
        $raw2 = $frame2->toRaw();
        static::assertSame(0x04, $raw2->flags);

        $frame3 = new HeadersFrame(1, 'h', true, false);
        $raw3 = $frame3->toRaw();
        static::assertSame(0x01, $raw3->flags);

        $frame4 = new HeadersFrame(1, 'h', false, false);
        $raw4 = $frame4->toRaw();
        static::assertSame(0x00, $raw4->flags);
    }

    public function testHeadersFrameFlagsEncodingWithPriority(): void
    {
        $frame = new HeadersFrame(1, 'h', true, true, 0, 16, false);
        $raw = $frame->toRaw();
        static::assertSame(0x25, $raw->flags);

        $frame2 = new HeadersFrame(1, 'h', false, false, 0, 16, false);
        $raw2 = $frame2->toRaw();
        static::assertSame(0x20, $raw2->flags);
    }

    public function testHeadersFrameWeightDefault16(): void
    {
        $frame = new HeadersFrame(1, 'hdr', false, true, 0, null, false);
        $raw = $frame->toRaw();

        static::assertSame(15, ord($raw->payload[4]));

        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame(16, $parsed->weight);
    }

    public function testHeadersFramePayloadConcatenationOrder(): void
    {
        $frame = new HeadersFrame(1, 'ABCD', false, true, 0, 16, false);
        $raw = $frame->toRaw();

        static::assertSame('ABCD', substr($raw->payload, 5));

        $frame2 = new HeadersFrame(1, 'EFGH', false, true);
        $raw2 = $frame2->toRaw();
        static::assertSame('EFGH', $raw2->payload);
    }

    public function testHeadersFrameExclusiveBitInToRaw(): void
    {
        $frame = new HeadersFrame(1, 'h', false, true, 10, 32, true);
        $raw = $frame->toRaw();

        $depValue = unpack('N', $raw->payload, 0)[1];
        static::assertNotSame(0, $depValue & 0x8000_0000);
        static::assertSame(10, $depValue & 0x7FFF_FFFF);

        $frame2 = new HeadersFrame(1, 'h', false, true, 10, 32, false);
        $raw2 = $frame2->toRaw();
        $depValue2 = unpack('N', $raw2->payload, 0)[1];
        static::assertSame(0, $depValue2 & 0x8000_0000);
        static::assertSame(10, $depValue2 & 0x7FFF_FFFF);
    }

    public function testHeadersFramePaddingDataLengthNegativeFails(): void
    {
        $raw = new RawFrame(FrameType::Headers->value, 0x0C, 1, chr(10) . 'abcd');
        $this->expectException(FrameDecodingException::class);
        HeadersFrame::fromRaw($raw);
    }

    public function testOriginFrameStreamIdIsZero(): void
    {
        $frame = new OriginFrame(['https://example.com']);
        static::assertSame(0, $frame->streamId);

        $raw = $frame->toRaw();
        static::assertSame(0, $raw->streamId);
    }

    public function testOriginFrameTruncatedOriginLengthFails(): void
    {
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, "\x00");
        $this->expectException(FrameDecodingException::class);
        OriginFrame::fromRaw($raw);
    }

    public function testOriginFrameTruncatedOriginValueFails(): void
    {
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, pack('n', 10) . 'abc');
        $this->expectException(FrameDecodingException::class);
        OriginFrame::fromRaw($raw);
    }

    public function testOriginFrameOriginLengthZeroFiltered(): void
    {
        $payload = pack('n', 0);
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, $payload);
        $parsed = OriginFrame::fromRaw($raw);
        static::assertSame([], $parsed->origins);
    }

    public function testOriginFrameFlagsByteIsZero(): void
    {
        $frame = new OriginFrame(['https://a.com']);
        $raw = $frame->toRaw();
        static::assertSame(0, $raw->flags);
    }

    public function testOriginFrameExactBytesRoundTrip(): void
    {
        $origins = ['https://example.com', 'https://cdn.example.com'];
        $frame = new OriginFrame($origins);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::Origin->value, $raw->type);
        static::assertSame(0, $raw->flags);
        static::assertSame(0, $raw->streamId);

        $offset = 0;
        foreach ($origins as $origin) {
            $len = unpack('n', $raw->payload, $offset)[1];
            static::assertSame(strlen($origin), $len);
            static::assertSame($origin, substr($raw->payload, $offset + 2, $len));
            $offset += 2 + $len;
        }

        static::assertSame(strlen($raw->payload), $offset);

        $parsed = OriginFrame::fromRaw($raw);
        static::assertSame($origins, $parsed->origins);
    }

    public function testOriginFrameMultipleOriginsWithZeroLengthEntry(): void
    {
        $payload = pack('n', 5) . 'a.com' . pack('n', 0) . pack('n', 5) . 'b.com';
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, $payload);
        $parsed = OriginFrame::fromRaw($raw);
        static::assertSame(['a.com', 'b.com'], $parsed->origins);
    }

    public function testOriginFrameEmptyPayloadSucceeds(): void
    {
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, '');
        $parsed = OriginFrame::fromRaw($raw);
        static::assertSame([], $parsed->origins);
    }

    public function testPingFrameStreamIdIsZero(): void
    {
        $frame = new PingFrame('12345678', false);
        static::assertSame(0, $frame->streamId);

        $raw = $frame->toRaw();
        static::assertSame(0, $raw->streamId);

        $ackFrame = new PingFrame('abcdefgh', true);
        $rawAck = $ackFrame->toRaw();
        static::assertSame(0, $rawAck->streamId);
    }

    public function testPingFrameExactBytesRoundTrip(): void
    {
        $frame = new PingFrame('ABCDEFGH', false);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::Ping->value, $raw->type);
        static::assertSame(0x00, $raw->flags);
        static::assertSame(0, $raw->streamId);
        static::assertSame('ABCDEFGH', $raw->payload);

        $parsed = PingFrame::fromRaw($raw);
        static::assertSame('ABCDEFGH', $parsed->opaqueData);
        static::assertFalse($parsed->ack);
    }

    public function testPingFrameAckExactFlags(): void
    {
        $frame = new PingFrame('12345678', true);
        $raw = $frame->toRaw();
        static::assertSame(0x01, $raw->flags);

        $parsed = PingFrame::fromRaw($raw);
        static::assertTrue($parsed->ack);
    }

    public function testPushPromiseFrameEndHeadersFlagValue(): void
    {
        $frame = new PushPromiseFrame(1, 2, 'headers', true);
        $raw = $frame->toRaw();
        static::assertSame(0x04, $raw->flags);

        $frame2 = new PushPromiseFrame(1, 2, 'headers', false);
        $raw2 = $frame2->toRaw();
        static::assertSame(0x00, $raw2->flags);
    }

    public function testPushPromiseFramePaddedFlagParsing(): void
    {
        $padLength = 2;
        $promisedStreamId = pack('N', 4);
        $payload = chr($padLength) . $promisedStreamId . 'hdr' . str_repeat("\x00", $padLength);
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);

        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(4, $parsed->promisedStreamId);
        static::assertSame('hdr', $parsed->headerBlockFragment);
        static::assertTrue($parsed->endHeaders);
    }

    public function testPushPromiseFramePaddedEmptyPayloadFails(): void
    {
        $raw = new RawFrame(FrameType::PushPromise->value, 0x08, 1, '');
        $this->expectException(ProtocolException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromiseFramePaddingLengthCalculation(): void
    {
        $payload = chr(20) . pack('N', 2) . 'x';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);
        $this->expectException(FrameDecodingException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromiseFramePromisedStreamIdMask(): void
    {
        $promisedStreamId = 0x8000_0002;
        $payload = pack('N', $promisedStreamId) . 'headers';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 1, $payload);

        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(2, $parsed->promisedStreamId);
    }

    public function testPushPromiseFrameOffsetCalculationsNoPadding(): void
    {
        $payload = pack('N', 3) . 'fragment';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 1, $payload);

        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(3, $parsed->promisedStreamId);
        static::assertSame('fragment', $parsed->headerBlockFragment);
    }

    public function testPushPromiseFrameToRawFlags(): void
    {
        $frame = new PushPromiseFrame(1, 2, 'h', true);
        $raw = $frame->toRaw();
        static::assertSame(0x04, $raw->flags);

        $frame2 = new PushPromiseFrame(1, 2, 'h', false);
        $raw2 = $frame2->toRaw();
        static::assertSame(0x00, $raw2->flags);
    }

    public function testPushPromiseFrameToRawStreamId(): void
    {
        $frame = new PushPromiseFrame(7, 10, 'h', true);
        $raw = $frame->toRaw();
        static::assertSame(7, $raw->streamId);
    }

    public function testPushPromiseFrameExactBytesRoundTrip(): void
    {
        $frame = new PushPromiseFrame(1, 2, 'promise-data', true);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::PushPromise->value, $raw->type);
        static::assertSame(0x04, $raw->flags);
        static::assertSame(1, $raw->streamId);

        $promisedId = unpack('N', $raw->payload, 0)[1] & 0x7FFF_FFFF;
        static::assertSame(2, $promisedId);
        static::assertSame('promise-data', substr($raw->payload, 4));

        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(1, $parsed->streamId);
        static::assertSame(2, $parsed->promisedStreamId);
        static::assertSame('promise-data', $parsed->headerBlockFragment);
        static::assertTrue($parsed->endHeaders);
    }

    public function testPushPromiseFramePromisedStreamIdMaskInToRaw(): void
    {
        $frame = new PushPromiseFrame(1, 2, 'h', true);
        $raw = $frame->toRaw();

        $rawPromisedId = unpack('N', $raw->payload, 0)[1];
        static::assertSame(0, $rawPromisedId & 0x8000_0000);
        static::assertSame(2, $rawPromisedId & 0x7FFF_FFFF);
    }

    public function testPushPromiseFrameMissingPromisedStreamIdFails(): void
    {
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 1, 'ab');
        $this->expectException(ProtocolException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testRstStreamFrameFlagsByteIsZero(): void
    {
        $frame = new RstStreamFrame(1, ErrorCode::Cancel);
        $raw = $frame->toRaw();
        static::assertSame(0, $raw->flags);
    }

    public function testRstStreamFrameExactBytesRoundTrip(): void
    {
        $frame = new RstStreamFrame(5, ErrorCode::ProtocolError);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::RstStream->value, $raw->type);
        static::assertSame(0, $raw->flags);
        static::assertSame(5, $raw->streamId);
        static::assertSame(4, strlen($raw->payload));

        $errorCodeValue = unpack('N', $raw->payload, 0)[1];
        static::assertSame(ErrorCode::ProtocolError->value, $errorCodeValue);

        $parsed = RstStreamFrame::fromRaw($raw);
        static::assertSame(5, $parsed->streamId);
        static::assertSame(ErrorCode::ProtocolError, $parsed->errorCode);
    }

    public function testSettingsFrameStreamIdIsZero(): void
    {
        $frame = new SettingsFrame([0x3 => 100], false);
        static::assertSame(0, $frame->streamId);

        $raw = $frame->toRaw();
        static::assertSame(0, $raw->streamId);
    }

    public function testSettingsFrameAckWithNonEmptyPayloadThrows(): void
    {
        $payload = pack('nN', 0x3, 100);
        $raw = new RawFrame(FrameType::Settings->value, 0x01, 0, $payload);
        $this->expectException(ProtocolException::class);
        SettingsFrame::fromRaw($raw);
    }

    public function testSettingsFrameAckWithEmptyPayloadSucceeds(): void
    {
        $raw = new RawFrame(FrameType::Settings->value, 0x01, 0, '');
        $parsed = SettingsFrame::fromRaw($raw);
        static::assertTrue($parsed->ack);
        static::assertSame([], $parsed->settings);
    }

    public function testSettingsFrameExactBytesRoundTrip(): void
    {
        $frame = new SettingsFrame([0x1 => 4096, 0x3 => 100], false);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::Settings->value, $raw->type);
        static::assertSame(0, $raw->flags);
        static::assertSame(0, $raw->streamId);
        static::assertSame(12, strlen($raw->payload));

        $parsed = SettingsFrame::fromRaw($raw);
        static::assertFalse($parsed->ack);
        static::assertSame(4096, $parsed->settings[0x1]);
        static::assertSame(100, $parsed->settings[0x3]);
    }

    public function testSettingsFrameAckFlagsEncoding(): void
    {
        $frame = new SettingsFrame([], true);
        $raw = $frame->toRaw();
        static::assertSame(0x01, $raw->flags);

        $frame2 = new SettingsFrame([], false);
        $raw2 = $frame2->toRaw();
        static::assertSame(0x00, $raw2->flags);
    }

    public function testWindowUpdateFrameIncrementMaskValue(): void
    {
        $payload = pack('N', 0x8000_0400);
        $raw = new RawFrame(FrameType::WindowUpdate->value, 0, 1, $payload);
        $parsed = WindowUpdateFrame::fromRaw($raw);
        static::assertSame(1024, $parsed->windowSizeIncrement);
    }

    public function testWindowUpdateFrameZeroIncrementFails(): void
    {
        $payload = pack('N', 0x8000_0000);
        $raw = new RawFrame(FrameType::WindowUpdate->value, 0, 1, $payload);
        $this->expectException(FrameDecodingException::class);
        WindowUpdateFrame::fromRaw($raw);
    }

    public function testWindowUpdateFrameFlagsByteIsZero(): void
    {
        $frame = new WindowUpdateFrame(1, 1024);
        $raw = $frame->toRaw();
        static::assertSame(0, $raw->flags);
    }

    public function testWindowUpdateFrameIncrementMaskInToRaw(): void
    {
        $frame = new WindowUpdateFrame(1, 1024);
        $raw = $frame->toRaw();

        $rawValue = unpack('N', $raw->payload, 0)[1];
        static::assertSame(0, $rawValue & 0x8000_0000);
        static::assertSame(1024, $rawValue & 0x7FFF_FFFF);
    }

    public function testWindowUpdateFrameExactBytesRoundTrip(): void
    {
        $frame = new WindowUpdateFrame(3, 65_535);
        $raw = $frame->toRaw();

        static::assertSame(FrameType::WindowUpdate->value, $raw->type);
        static::assertSame(0, $raw->flags);
        static::assertSame(3, $raw->streamId);
        static::assertSame(4, strlen($raw->payload));

        $increment = unpack('N', $raw->payload, 0)[1] & 0x7FFF_FFFF;
        static::assertSame(65_535, $increment);

        $parsed = WindowUpdateFrame::fromRaw($raw);
        static::assertSame(3, $parsed->streamId);
        static::assertSame(65_535, $parsed->windowSizeIncrement);
    }

    public function testWindowUpdateFrameMaxIncrement(): void
    {
        $maxIncrement = 0x7FFF_FFFF;
        $frame = new WindowUpdateFrame(0, $maxIncrement);
        $raw = $frame->toRaw();

        $parsed = WindowUpdateFrame::fromRaw($raw);
        static::assertSame($maxIncrement, $parsed->windowSizeIncrement);
    }

    public function testWindowUpdateFrameConnectionLevel(): void
    {
        $frame = new WindowUpdateFrame(0, 512);
        $raw = $frame->toRaw();
        static::assertSame(0, $raw->streamId);

        $parsed = WindowUpdateFrame::fromRaw($raw);
        static::assertSame(0, $parsed->streamId);
        static::assertSame(512, $parsed->windowSizeIncrement);
    }

    public function testAltSvcFramePayloadTooShortForOriginLengthExact(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 5) . 'abcd');
        $this->expectException(FrameDecodingException::class);
        AltSvcFrame::fromRaw($raw);
    }

    public function testAltSvcFramePayloadExactly2PlusOriginLengthSucceeds(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 3) . 'abc');
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('abc', $parsed->origin);
        static::assertSame('', $parsed->fieldValue);
    }

    public function testAltSvcFramePayloadExactly1PlusOriginLengthFails(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 1));
        $this->expectException(FrameDecodingException::class);
        AltSvcFrame::fromRaw($raw);
    }

    public function testAltSvcFrameOriginLengthZeroGivesEmptyStringNotSubstr(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 0) . 'field-value');
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('', $parsed->origin);
        static::assertSame('field-value', $parsed->fieldValue);
    }

    public function testDataFramePadLengthEqualToPayloadLengthThrows(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(2) . 'x');
        $this->expectException(FrameDecodingException::class);
        DataFrame::fromRaw($raw);
    }

    public function testDataFrameThrowIsNotRemovedForInvalidPadding(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(255) . 'x');
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('padding');
        DataFrame::fromRaw($raw);
    }

    public function testHeadersFramePaddedWithPayloadLength1Succeeds(): void
    {
        $raw = new RawFrame(FrameType::Headers->value, 0x0C, 1, chr(0));
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame('', $parsed->headerBlockFragment);
    }

    public function testHeadersFramePriorityExactly5BytesAvailable(): void
    {
        $depValue = 0;
        $payload = pack('NC', $depValue, 15);
        $raw = new RawFrame(FrameType::Headers->value, 0x24, 1, $payload);
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame(0, $parsed->streamDependency);
        static::assertSame(16, $parsed->weight);
        static::assertSame('', $parsed->headerBlockFragment);
    }

    public function testHeadersFramePriorityWith4BytesFails(): void
    {
        $raw = new RawFrame(FrameType::Headers->value, 0x24, 1, 'abcd');
        $this->expectException(ProtocolException::class);
        HeadersFrame::fromRaw($raw);
    }

    public function testHeadersFrameToRawEndStreamFlagIsOred(): void
    {
        $frame = new HeadersFrame(1, 'h', true, true, 0, 16, false);
        $raw = $frame->toRaw();
        static::assertSame(0x25, $raw->flags);
    }

    public function testHeadersFrameToRawPayloadConcatenation(): void
    {
        $frame = new HeadersFrame(1, 'HEADER', false, true, 5, 32, true);
        $raw = $frame->toRaw();
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame('HEADER', $parsed->headerBlockFragment);
        static::assertSame(5, $parsed->streamDependency);
        static::assertSame(32, $parsed->weight);
    }

    public function testOriginFrameTruncatedOriginLengthWith3BytePayload(): void
    {
        $payload = pack('n', 1) . 'A';
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, $payload);
        $parsed = OriginFrame::fromRaw($raw);
        static::assertSame(['A'], $parsed->origins);
    }

    public function testOriginFrameTruncatedValueWith5BytePayloadFails(): void
    {
        $payload = pack('n', 4) . 'AB';
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, $payload);
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('truncated origin value');
        OriginFrame::fromRaw($raw);
    }

    public function testPushPromiseFrameEndHeadersFlagBit(): void
    {
        $payload = pack('N', 2) . 'headers';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x01, 1, $payload);
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertFalse($parsed->endHeaders);
    }

    public function testPushPromiseFramePaddedFlagBit(): void
    {
        $payload = pack('N', 2) . 'headers';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x01, 1, $payload);
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame('headers', $parsed->headerBlockFragment);
    }

    public function testPushPromiseFramePaddedWithPayloadLength1Succeeds(): void
    {
        $payload = chr(0) . pack('N', 2) . 'h';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x08, 1, $payload);
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(2, $parsed->promisedStreamId);
        static::assertSame('h', $parsed->headerBlockFragment);
    }

    public function testPushPromiseFrameMissingStreamIdFails(): void
    {
        $raw = new RawFrame(FrameType::PushPromise->value, 0x00, 1, 'abc');
        $this->expectException(ProtocolException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromiseFrameExactly4BytesSucceeds(): void
    {
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 1, pack('N', 2));
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(2, $parsed->promisedStreamId);
        static::assertSame('', $parsed->headerBlockFragment);
    }

    public function testPushPromiseFrameDataLengthZeroSucceeds(): void
    {
        $raw = new RawFrame(FrameType::PushPromise->value, 0x04, 1, pack('N', 2));
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame('', $parsed->headerBlockFragment);
    }

    public function testPushPromiseFrameToRawPromisedStreamIdMask(): void
    {
        $frame = new PushPromiseFrame(1, 2_147_483_647, 'hdr', true);
        $raw = $frame->toRaw();
        $streamIdBytes = unpack('N', substr($raw->payload, 0, 4))[1];
        static::assertSame(2_147_483_647, $streamIdBytes & 0x7FFF_FFFF);
    }

    public function testWindowUpdateZeroIncrementErrorMessageContainsZero(): void
    {
        $raw = new RawFrame(FrameType::WindowUpdate->value, 0x00, 1, pack('N', 0));

        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('got 0');

        WindowUpdateFrame::fromRaw($raw);
    }

    public function testHeadersFramePriorityWithPaddedOffsetSubtraction(): void
    {
        $padLength = 3;
        $depValue = 7;
        $payload = chr($padLength) . pack('NC', $depValue, 99) . 'hdr' . str_repeat("\x00", $padLength);
        $raw = new RawFrame(FrameType::Headers->value, 0x2C, 1, $payload);
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame(7, $parsed->streamDependency);
        static::assertSame(100, $parsed->weight);
        static::assertSame('hdr', $parsed->headerBlockFragment);
    }

    public function testHeadersFrameToRawPayloadAppendNotAssign(): void
    {
        $frame = new HeadersFrame(1, 'BLOCK', true, true, 5, 32, false);
        $raw = $frame->toRaw();
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame('BLOCK', $parsed->headerBlockFragment);
        static::assertSame(5, $parsed->streamDependency);
        static::assertSame(32, $parsed->weight);
        static::assertTrue($parsed->endStream);
        static::assertTrue($parsed->endHeaders);
        static::assertSame(0x25, $raw->flags);
    }

    public function testOriginFrameSingleByteRemainingThrowsTruncated(): void
    {
        $payload = pack('n', 3) . 'abc' . "\x00";
        $raw = new RawFrame(FrameType::Origin->value, 0, 0, $payload);
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('truncated origin length');
        OriginFrame::fromRaw($raw);
    }

    public function testPushPromiseFramePaddedWithPayloadLengthExactly1Fails(): void
    {
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, chr(0));
        $this->expectException(ProtocolException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromiseFramePaddedWithPadLengthAndOffsetSubtraction(): void
    {
        $padLength = 1;
        $payload = chr($padLength) . pack('N', 3) . 'frag' . "\x00";
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(3, $parsed->promisedStreamId);
        static::assertSame('frag', $parsed->headerBlockFragment);
    }

    public function testPushPromiseFrameInsufficientDataAfterPaddingOffsetFails(): void
    {
        $padLength = 0;
        $payload = chr($padLength) . 'ab';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);
        $this->expectException(ProtocolException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testAltSvcOriginLengthZeroUsesEmptyStringPath(): void
    {
        $payload = pack('n', 0) . 'h2=":443"';
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, $payload);
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('', $parsed->origin);
        static::assertSame('h2=":443"', $parsed->fieldValue);
    }

    public function testAltSvcOriginLengthZeroProducesEmptyNotSubstrResult(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 0));
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('', $parsed->origin);
        static::assertIsString($parsed->origin);
        static::assertSame(0, strlen($parsed->origin));
    }

    public function testAltSvcOriginLengthOneUsesSubstrNotEmpty(): void
    {
        $raw = new RawFrame(FrameType::AltSvc->value, 0, 0, pack('n', 1) . 'Xval');
        $parsed = AltSvcFrame::fromRaw($raw);
        static::assertSame('X', $parsed->origin);
        static::assertSame('val', $parsed->fieldValue);
    }

    public function testDataFramePadLengthEqualPayloadLengthIsRejected(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(1));
        $this->expectException(FrameDecodingException::class);
        DataFrame::fromRaw($raw);
    }

    public function testDataFramePadLengthOneMoreThanPayloadFails(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(3) . 'ab');
        $this->expectException(FrameDecodingException::class);
        DataFrame::fromRaw($raw);
    }

    public function testDataFramePadLengthOneLessThanPayloadSucceeds(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(1) . 'ab');
        $parsed = DataFrame::fromRaw($raw);
        static::assertSame('a', $parsed->data);
    }

    public function testDataFrameThrowOnInvalidPaddingProducesException(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(200));

        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('padding');

        DataFrame::fromRaw($raw);
    }

    public function testDataFrameExactBoundaryPadEqualsPayloadLengthMinusOne(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(2) . 'ab');
        $parsed = DataFrame::fromRaw($raw);
        static::assertSame('', $parsed->data);
    }

    public function testDataFramePadLengthZeroSucceeds(): void
    {
        $raw = new RawFrame(FrameType::Data->value, 0x08, 1, chr(0) . 'data');
        $parsed = DataFrame::fromRaw($raw);
        static::assertSame('data', $parsed->data);
    }

    public function testHeadersFramePriorityMinusOffsetDetectsShortPayload(): void
    {
        $raw = new RawFrame(FrameType::Headers->value, 0x28, 1, chr(0) . 'abcd');
        $this->expectException(ProtocolException::class);
        HeadersFrame::fromRaw($raw);
    }

    public function testHeadersFramePriorityMinusOffsetWithPadOffset(): void
    {
        $padLength = 0;
        $payload = chr($padLength) . pack('NC', 0, 0) . 'hdr';
        $raw = new RawFrame(FrameType::Headers->value, 0x2C, 1, $payload);
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame('hdr', $parsed->headerBlockFragment);
    }

    public function testHeadersFramePriorityWithLargePadAndOffset(): void
    {
        $padLength = 2;
        $payload = chr($padLength) . pack('NC', 1, 0) . 'xyz' . str_repeat("\x00", $padLength);
        $raw = new RawFrame(FrameType::Headers->value, 0x2C, 1, $payload);
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame(1, $parsed->streamDependency);
        static::assertSame('xyz', $parsed->headerBlockFragment);
    }

    public function testHeadersFrameEndStreamFlagOrPreservesOtherFlags(): void
    {
        $frame = new HeadersFrame(1, 'h', true, true, 0, 16, false);
        $raw = $frame->toRaw();
        static::assertSame(0x01 | 0x04 | 0x20, $raw->flags);
        static::assertNotSame(0x01, $raw->flags);
    }

    public function testHeadersFrameEndStreamFlagIsOredNotAssigned(): void
    {
        $frame = new HeadersFrame(1, 'h', true, false);
        $raw = $frame->toRaw();
        static::assertSame(0x01, $raw->flags);

        $frame2 = new HeadersFrame(1, 'h', true, true);
        $raw2 = $frame2->toRaw();
        static::assertSame(0x05, $raw2->flags);
    }

    public function testHeadersFrameOnlyEndStreamNoOtherFlags(): void
    {
        $frame = new HeadersFrame(1, 'x', true, false);
        $raw = $frame->toRaw();
        static::assertSame(0x01, $raw->flags);
    }

    public function testHeadersFramePayloadAppendNotAssignForPriority(): void
    {
        $frame = new HeadersFrame(1, 'DATA', true, true, 0, 16, false);
        $raw = $frame->toRaw();
        static::assertGreaterThan(4, strlen($raw->payload));
        static::assertSame('DATA', substr($raw->payload, 5));
    }

    public function testHeadersFramePriorityPayloadIsPrependedToFragment(): void
    {
        $frame = new HeadersFrame(1, 'XYZ', false, true, 10, 64, false);
        $raw = $frame->toRaw();
        $parsed = HeadersFrame::fromRaw($raw);
        static::assertSame('XYZ', $parsed->headerBlockFragment);
        static::assertSame(10, $parsed->streamDependency);
        static::assertSame(64, $parsed->weight);
    }

    public function testHeadersFrameNoPriorityPayloadIsJustFragment(): void
    {
        $frame = new HeadersFrame(1, 'FRAG', false, true);
        $raw = $frame->toRaw();
        static::assertSame('FRAG', $raw->payload);
    }

    public function testPushPromiseMinusNotPlusForPadLengthCheck(): void
    {
        $padLength = 3;
        $payload = chr($padLength) . 'ab';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);
        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('padding');
        PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromisePaddedLargerPadLengthFails(): void
    {
        $padLength = 10;
        $payload = chr($padLength) . pack('N', 2) . 'h';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);
        $this->expectException(FrameDecodingException::class);
        PushPromiseFrame::fromRaw($raw);
    }

    public function testPushPromisePaddedZeroPadSucceeds(): void
    {
        $padLength = 0;
        $payload = chr($padLength) . pack('N', 5) . 'frag';
        $raw = new RawFrame(FrameType::PushPromise->value, 0x0C, 1, $payload);
        $parsed = PushPromiseFrame::fromRaw($raw);
        static::assertSame(5, $parsed->promisedStreamId);
        static::assertSame('frag', $parsed->headerBlockFragment);
    }
}
