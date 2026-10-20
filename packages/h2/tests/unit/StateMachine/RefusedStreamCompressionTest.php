<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\H2\StreamState;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

final class RefusedStreamCompressionTest extends TestCase
{
    #[DataProvider('fragmentation')]
    public function testRefusalKeepsCompressionState(bool $continued): void
    {
        $sm = new StateMachine(false, [Setting::MaxConcurrentStreams->value => 1]);
        $encoder = new Encoder();
        $request = [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')];
        $sm->receive(new HeadersFrame(1, $encoder->encode($request), false, true)->toRaw());
        $headers = [...$request, new Header('x-refused', 'stored-in-dynamic-table')];
        $block = $encoder->encode($headers);
        if ($continued) {
            static::assertSame([[], []], $sm->receive(new HeadersFrame(3, substr($block, 0, 1), true, false)->toRaw()));
            [$frames, $events] = $sm->receive(new ContinuationFrame(3, substr($block, 1), true)->toRaw());
        } else {
            [$frames, $events] = $sm->receive(new HeadersFrame(3, $block, true, true)->toRaw());
        }

        static::assertSame([], $events);
        static::assertCount(1, $frames);
        static::assertSame(ErrorCode::RefusedStream, RstStreamFrame::fromRaw($frames[0])->errorCode);
        static::assertSame(1, $sm->activeStreamCount());
        $sm->resetStream(1, ErrorCode::Cancel);
        [, $events] = $sm->receive(new HeadersFrame(5, $encoder->encode($headers), true, true)->toRaw());
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals($headers, $events[0]->headers);
        static::assertSame(StreamState::Closed, $sm->getStreamState(3));
    }

    public function testRefusedBlockStillReportsCompressionErrors(): void
    {
        $sm = new StateMachine(false, [Setting::MaxConcurrentStreams->value => 0]);
        try {
            $sm->receive(new HeadersFrame(1, "\xff", true, true)->toRaw());
            static::fail('Expected a compression error.');
        } catch (ProtocolException $exception) {
            static::assertSame(ErrorCode::CompressionError, $exception->errorCode);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function fragmentation(): iterable
    {
        yield 'complete' => [false];
        yield 'continued' => [true];
    }
}
