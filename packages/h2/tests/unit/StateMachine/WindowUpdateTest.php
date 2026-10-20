<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\WindowUpdated;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Frame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function pack;

use const Psl\H2\MAX_WINDOW_SIZE;

final class WindowUpdateTest extends TestCase
{
    public function testWindowUpdateOnConnectionLevel(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $frames = $sm->windowUpdate(0, 1024);

        static::assertCount(1, $frames);
        static::assertSame(FrameType::WindowUpdate->value, $frames[0]->type);
        static::assertSame(0, $frames[0]->streamId);
    }

    public function testWindowUpdateOnStreamLevel(): void
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

        $frames = $sm->windowUpdate(1, 512);

        static::assertCount(1, $frames);
        $parsed = WindowUpdateFrame::fromRaw($frames[0]);
        static::assertSame(1, $parsed->streamId);
        static::assertSame(512, $parsed->windowSizeIncrement);
    }

    public function testWindowUpdateOnUnknownStreamSucceeds(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $frames = $sm->windowUpdate(99, 1024);

        static::assertCount(1, $frames);
    }

    public function testReceiveWindowUpdateWithInvalidPayloadThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $sm->receive(new HeadersFrame(1, $block, true, true)->toRaw());

        $raw = new RawFrame(FrameType::WindowUpdate->value, 0x00, 1, 'abc');

        $this->expectException(FrameDecodingException::class);
        $this->expectExceptionMessage('4 bytes');

        $sm->receive($raw);
    }

    public function testReceiveWindowUpdateWithZeroIncrementThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $sm->receive(new HeadersFrame(1, $block, true, true)->toRaw());

        $raw = new RawFrame(FrameType::WindowUpdate->value, 0x00, 1, pack('N', 0));

        $this->expectException(FrameDecodingException::class);

        $sm->receive($raw);
    }

    public function testReceiveConnectionWindowUpdate(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $raw = new WindowUpdateFrame(0, 1000)->toRaw();
        [, $events] = $sm->receive($raw);

        static::assertCount(1, $events);
        static::assertInstanceOf(WindowUpdated::class, $events[0]);
        static::assertSame(0, $events[0]->streamId);
        static::assertSame(1000, $events[0]->increment);
    }

    public function testReceiveStreamWindowUpdate(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')]);

        $raw = new WindowUpdateFrame($streamId, 2000)->toRaw();
        [, $events] = $sm->receive($raw);

        static::assertCount(1, $events);
        static::assertInstanceOf(WindowUpdated::class, $events[0]);
        static::assertSame($streamId, $events[0]->streamId);
        static::assertSame(2000, $events[0]->increment);
    }

    public function testConnectionLevelWindowOverflowThrows(): void
    {
        $sm = new StateMachine(false);
        $this->expectException(FlowControlException::class);
        $sm->receive(new WindowUpdateFrame(0, MAX_WINDOW_SIZE)->toRaw());
    }

    public function testStreamLevelWindowOverflowReturnsRstStream(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $sm->receive(new HeadersFrame(1, $block, false, true)->toRaw());

        $raw = new WindowUpdateFrame(1, MAX_WINDOW_SIZE)->toRaw();
        [$responseFrames, $_] = $sm->receive($raw);

        static::assertCount(1, $responseFrames);
        $rst = RstStreamFrame::fromRaw($responseFrames[0]);
        static::assertSame(1, $rst->streamId);
        static::assertSame(ErrorCode::FlowControlError, $rst->errorCode);
    }
}
