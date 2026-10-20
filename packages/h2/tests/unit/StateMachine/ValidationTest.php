<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;

use const Psl\H2\MAX_STREAM_ID;
use const Psl\H2\MAX_WINDOW_SIZE;

final class ValidationTest extends TestCase
{
    public function testSendHeadersRejectsZeroStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->sendHeadersEncoded(0, [new Header(':status', '200')]);
    }

    public function testSendHeadersRejectsNegativeStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->sendHeadersEncoded(-1, [new Header(':status', '200')]);
    }

    public function testSendDataRejectsZeroStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->sendData(0, 'data');
    }

    public function testResetStreamRejectsZeroStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->resetStream(0, ErrorCode::Cancel);
    }

    public function testPingRejectsEmptyOpaqueData(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('PING opaque data must not be empty');

        $sm->ping('');
    }

    public function testWindowUpdateRejectsNegativeStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 0 and');

        $sm->windowUpdate(-1, 1024);
    }

    public function testWindowUpdateRejectsZeroIncrement(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('WINDOW_UPDATE increment');

        $sm->windowUpdate(0, 0);
    }

    public function testWindowUpdateRejectsIncrementExceedingMax(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('WINDOW_UPDATE increment');

        $sm->windowUpdate(0, MAX_WINDOW_SIZE + 1);
    }

    public function testSendPushPromiseRejectsZeroAssociatedStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->sendPushPromise(0, 2, [new Header(':status', '200')]);
    }

    public function testSendPushPromiseRejectsZeroPromisedStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->sendPushPromise(1, 0, [new Header(':status', '200')]);
    }

    public function testSendHeadersRejectsStreamIdExceedingMax(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 1 and');

        $sm->sendHeadersEncoded(MAX_STREAM_ID + 1, [new Header(':status', '200')]);
    }

    public function testWindowUpdateRejectsStreamIdExceedingMax(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream ID must be between 0 and');

        $sm->windowUpdate(MAX_STREAM_ID + 1, 1024);
    }

    public function testWindowUpdateStreamOverflow(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $sm->receive($headersRaw);

        $this->expectException(FlowControlException::class);
        $this->expectExceptionMessage('overflow');

        $sm->windowUpdate(1, MAX_WINDOW_SIZE);
    }

    public function testWindowUpdateConnectionOverflow(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(FlowControlException::class);
        $this->expectExceptionMessage('overflow');

        $sm->windowUpdate(0, MAX_WINDOW_SIZE);
    }

    public function testRejectsEvenStreamIdFromClient(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('expected odd');

        $raw = new HeadersFrame(2, $block, true, true)->toRaw();
        $sm->receive($raw);
    }

    public function testRejectsOddStreamIdFromServer(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':status', '200'),
        ]);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('expected even');

        $raw = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw);
    }

    public function testDiscardsHeadersOnSkippedClosedStream(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $raw3 = new HeadersFrame(3, $block, true, true)->toRaw();
        $sm->receive($raw3);

        $raw1 = new HeadersFrame(1, $block, true, true)->toRaw();
        static::assertSame([[], []], $sm->receive($raw1));
        static::assertSame(1, $sm->activeStreamCount());
    }

    public function testAcceptsMonotonicallyIncreasingStreamIds(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $raw1 = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw1);

        $raw3 = new HeadersFrame(3, $block, true, true)->toRaw();
        $sm->receive($raw3);

        $raw5 = new HeadersFrame(5, $block, true, true)->toRaw();
        $sm->receive($raw5);

        static::assertTrue(true);
    }

    public function testFrameSizeValidationRejectsOversizedStreamFrame(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $oversizedPayload = str_repeat('x', 16_385);
        $raw = new RawFrame(0x0, 0x00, 1, $oversizedPayload);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('frame size');

        $sm->receive($raw);
    }

    public function testFrameSizeValidationRejectsOversizedConnectionFrames(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new RawFrame(0xFF, 0x00, 0, str_repeat('x', 16_385));
        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testReceiveWindowEnforcement(): void
    {
        $sm = new StateMachine(false, [
            Setting::InitialWindowSize->value => 100,
        ]);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $sm->receive($headersRaw);

        $this->expectException(FlowControlException::class);

        $dataRaw = new DataFrame(1, str_repeat('x', 101), false)->toRaw();
        $sm->receive($dataRaw);
    }

    public function testMaxConcurrentStreamsOnReceive(): void
    {
        $sm = new StateMachine(false, [
            Setting::MaxConcurrentStreams->value => 1,
        ]);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $raw1 = new HeadersFrame(1, $block, false, true)->toRaw();
        $sm->receive($raw1);

        $raw3 = new HeadersFrame(3, $block, false, true)->toRaw();
        [$responseFrames, $events] = $sm->receive($raw3);

        static::assertCount(1, $responseFrames);
        $parsed = RstStreamFrame::fromRaw($responseFrames[0]);
        static::assertSame(ErrorCode::RefusedStream, $parsed->errorCode);
    }

    public function testHeaderValidationRejectsPseudoAfterRegular(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header('host', 'example.com'),
            new Header(':path', '/'),
        ]);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('after regular');

        $raw = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw);
    }

    public function testHeaderValidationRejectsBannedHeader(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header('connection', 'keep-alive'),
        ]);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Banned');

        $raw = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw);
    }

    public function testHeaderValidationRejectsUppercaseHeader(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header('Content-Type', 'text/html'),
        ]);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('uppercase');

        $raw = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw);
    }
}
