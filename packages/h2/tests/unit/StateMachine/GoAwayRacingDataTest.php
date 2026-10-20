<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\AltSvcFrame;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PriorityFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\H2\StreamState;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;
use function substr;

final class GoAwayRacingDataTest extends TestCase
{
    public function testRacingPostDoesNotStopAcceptedStream(): void
    {
        $state = new StateMachine(false);
        $encoder = new Encoder();
        $request = [new Header(':method', 'POST'), new Header(':scheme', 'https'), new Header(':path', '/')];
        $state->receive(new HeadersFrame(1, $encoder->encode($request), false, true)->toRaw());
        $state->goAway(ErrorCode::NoError);
        $trailers = [new Header('x-raced', 'indexed-by-ignored-request')];
        static::assertSame(
            [[], []],
            $state->receive(new HeadersFrame(3, $encoder->encode([...$request, ...$trailers]), false, true)->toRaw()),
        );

        for ($i = 0; $i < 5; $i++) {
            [$responses, $events] = $state->receive(
                new RawFrame(0, 0x08, 3, "\x03" . str_repeat('x', 16_380) . "\0\0\0"),
            );
            static::assertSame([], $events);
            static::assertCount(1, $responses);
            static::assertInstanceOf(RawFrame::class, $responses[0]);
            $update = WindowUpdateFrame::fromRaw($responses[0]);
            static::assertSame(0, $update->streamId);
            static::assertSame(16_384, $update->windowSizeIncrement);
        }

        static::assertSame([[], []], $state->receive(new DataFrame(3, '', true)->toRaw()));
        static::assertSame(1, $state->lastPeerStreamId());
        static::assertSame(1, $state->activeStreamCount());
        static::assertSame(StreamState::Idle, $state->getStreamState(3));
        [, $events] = $state->receive(new DataFrame(1, 'accepted', false)->toRaw());
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame('accepted', $events[0]->data);
        [, $events] = $state->receive(new HeadersFrame(1, $encoder->encode($trailers), true, true)->toRaw());
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals($trailers, $events[0]->headers);
        $state->sendResponseHeadersEncoded(1, '200', [], true);
        static::assertSame(0, $state->activeStreamCount());
    }

    #[DataProvider('provideControls')]
    public function testLateControlFramesAboveGoAwayAreIgnored(RawFrame $frame): void
    {
        $state = new StateMachine(false);
        $state->goAway(ErrorCode::NoError);

        static::assertSame([[], []], $state->receive($frame));
        static::assertSame(0, $state->lastPeerStreamId());
        static::assertSame(0, $state->activeStreamCount());
        static::assertSame(StreamState::Idle, $state->getStreamState(3));
    }

    public function testRacingDataStillConsumesConnectionWindow(): void
    {
        $state = new StateMachine(false, [Setting::MaxFrameSize->value => 65_536]);
        $state->goAway(ErrorCode::NoError);

        $this->expectException(FlowControlException::class);
        $state->receive(new DataFrame(3, str_repeat('x', 65_536), true)->toRaw());
    }

    #[DataProvider('providePushBlocks')]
    public function testRacingPushOnlyUpdatesCompression(bool $continued): void
    {
        $state = new StateMachine(true);
        $encoder = new Encoder();
        $request = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header(':authority', 'example.com'),
        ];
        $state->sendHeadersEncoded(1, $request, true);
        $state->goAway(ErrorCode::NoError);
        $fields = [new Header('x-raced', 'indexed-by-ignored-push')];
        $block = $encoder->encode([...$request, ...$fields]);

        if ($continued) {
            static::assertSame(
                [[], []],
                $state->receive(new PushPromiseFrame(1, 2, substr($block, 0, 1), false)->toRaw()),
            );
            $result = $state->receive(new ContinuationFrame(1, substr($block, 1), true)->toRaw());
        } else {
            $result = $state->receive(new PushPromiseFrame(1, 2, $block, true)->toRaw());
        }

        static::assertSame([[], []], $result);
        static::assertSame(0, $state->lastPeerStreamId());
        static::assertSame(1, $state->activeStreamCount());
        static::assertSame(StreamState::Idle, $state->getStreamState(2));
        $response = [new Header(':status', '200'), ...$fields];
        [, $events] = $state->receive(new HeadersFrame(1, $encoder->encode($response), true, true)->toRaw());
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals($response, $events[0]->headers);
        static::assertSame(0, $state->activeStreamCount());
    }

    public function testLocalStreamAboveCutoffIsNotIgnored(): void
    {
        $state = new StateMachine(false);
        $state->goAway(ErrorCode::NoError);

        $this->expectException(ProtocolException::class);
        $state->receive(new DataFrame(2, 'invalid idle local stream', true)->toRaw());
    }

    /** @return iterable<string, array{RawFrame}> */
    public static function provideControls(): iterable
    {
        yield 'WINDOW_UPDATE' => [new WindowUpdateFrame(3, 1)->toRaw()];
        yield 'RST_STREAM' => [new RstStreamFrame(3, ErrorCode::Cancel)->toRaw()];
        yield 'PRIORITY' => [new PriorityFrame(3, 3, 16, false)->toRaw()];
        yield 'ALTSVC' => [new AltSvcFrame(3, '', 'h3=":443"')->toRaw()];
    }

    /** @return iterable<string, array{bool}> */
    public static function providePushBlocks(): iterable
    {
        yield 'PUSH_PROMISE' => [false];
        yield 'PUSH_PROMISE and CONTINUATION' => [true];
    }
}
