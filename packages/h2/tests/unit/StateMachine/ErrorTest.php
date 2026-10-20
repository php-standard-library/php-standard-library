<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function pack;

final class ErrorTest extends TestCase
{
    public function testHeaderBlockInterrupted(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([new Header(':method', 'GET')]);

        $headersRaw = new HeadersFrame(1, $block, false, false)->toRaw();
        $sm->receive($headersRaw);

        $this->expectException(ProtocolException::class);

        $dataRaw = new DataFrame(1, 'data', false)->toRaw();
        $sm->receive($dataRaw);
    }

    public function testContinuationForWrongStream(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([new Header(':method', 'GET')]);

        $headersRaw = new HeadersFrame(1, $block, false, false)->toRaw();
        $sm->receive($headersRaw);

        $this->expectException(ProtocolException::class);

        $contRaw = new ContinuationFrame(3, 'data', true)->toRaw();
        $sm->receive($contRaw);
    }

    public function testUnknownFrameTypeIgnored(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new RawFrame(0xFE, 0x00, 0, 'unknown');
        [$responseFrames, $events] = $sm->receive($raw);

        static::assertSame([], $responseFrames);
        static::assertSame([], $events);
    }

    public function testPingWithNonZeroStreamIdThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new RawFrame(FrameType::Ping->value, 0, 1, '12345678');

        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testSettingsWithNonZeroStreamIdThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new RawFrame(FrameType::Settings->value, 0, 1, '');

        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testPriorityWithZeroStreamIdThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new RawFrame(FrameType::Priority->value, 0, 0, pack('NC', 0, 15));

        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testPriorityWithWrongLengthThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($headersRaw);

        $raw = new RawFrame(FrameType::Priority->value, 0, 1, pack('NC', 0, 15) . "\x00");

        $this->expectException(StreamException::class);
        $sm->receive($raw);
    }

    public function testRstStreamOnIdleStreamThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new RstStreamFrame(1, ErrorCode::Cancel)->toRaw();

        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testWindowUpdateOnIdleStreamThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new WindowUpdateFrame(1, 1000)->toRaw();

        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testContinuationOnIdleStreamThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $raw = new ContinuationFrame(1, 'data', true)->toRaw();

        $this->expectException(ProtocolException::class);
        $sm->receive($raw);
    }

    public function testSelfDependentHeadersThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $raw = new RawFrame(FrameType::Headers->value, 0x20 | 0x04, 1, pack('NC', 1, 15) . $block);

        $this->expectException(StreamException::class);
        $sm->receive($raw);
    }

    public function testHeaderBlockInterruptedErrorContainsStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([new Header(':method', 'GET')]);

        $headersRaw = new HeadersFrame(1, $block, false, false)->toRaw();
        $sm->receive($headersRaw);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('stream 1');

        $dataRaw = new DataFrame(1, 'data', false)->toRaw();
        $sm->receive($dataRaw);
    }
}
