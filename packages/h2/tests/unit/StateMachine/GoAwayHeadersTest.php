<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

final class GoAwayHeadersTest extends TestCase
{
    #[DataProvider('blocks')]
    public function testRacingHeadersOnlyUpdateCompression(bool $continued): void
    {
        $state = new StateMachine(false);
        $encoder = new Encoder();
        $request = [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')];
        $state->receive(new HeadersFrame(1, $encoder->encode($request), false, true)->toRaw());
        $state->goAway(ErrorCode::NoError);
        $fields = [new Header('x-raced', 'table-entry-from-an-ignored-stream')];
        $block = $encoder->encode([...$request, ...$fields]);
        if ($continued) {
            static::assertSame(
                [[], []],
                $state->receive(new HeadersFrame(3, substr($block, 0, 1), true, false)->toRaw()),
            );
            $result = $state->receive(new ContinuationFrame(3, substr($block, 1), true)->toRaw());
        } else {
            $result = $state->receive(new HeadersFrame(3, $block, true, true)->toRaw());
        }

        static::assertSame([[], []], $result);
        static::assertSame(1, $state->lastPeerStreamId());
        static::assertSame(1, $state->activeStreamCount());
        [, $events] = $state->receive(new HeadersFrame(1, $encoder->encode($fields), true, true)->toRaw());
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals($fields, $events[0]->headers);
    }

    /** @return iterable<string, array{bool}> */
    public static function blocks(): iterable
    {
        yield 'HEADERS' => [false];
        yield 'HEADERS and CONTINUATION' => [true];
    }
}
