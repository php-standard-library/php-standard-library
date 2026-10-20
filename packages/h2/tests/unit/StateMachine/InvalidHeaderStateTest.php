<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

final class InvalidHeaderStateTest extends TestCase
{
    #[DataProvider('states')]
    public function testDecodesBeforeRejectingInvalidStreamState(bool $reserved, bool $continued): void
    {
        $state = new StateMachine(false);
        $encoder = new Encoder();
        $request = [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')];
        $state->receive(new HeadersFrame(1, $encoder->encode($request), true, true)->toRaw());
        $streamId = 1;
        if ($reserved) {
            $state->sendPushPromise(1, 2, $request);
            $streamId = 2;
        }

        $fields = [new Header('x-rejected', 'indexed-even-on-invalid-stream')];
        $block = $encoder->encode($fields);
        try {
            if ($continued) {
                static::assertSame(
                    [[], []],
                    $state->receive(new HeadersFrame($streamId, substr($block, 0, 1), true, false)->toRaw()),
                );
                $state->receive(new ContinuationFrame($streamId, substr($block, 1), true)->toRaw());
            } else {
                $state->receive(new HeadersFrame($streamId, $block, true, true)->toRaw());
            }

            static::fail('Expected HEADERS to fail in this stream state.');
        } catch (ProtocolException|StreamException $error) {
            if ($reserved) {
                static::assertInstanceOf(ProtocolException::class, $error);
                static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            } else {
                static::assertInstanceOf(StreamException::class, $error);
                static::assertSame(ErrorCode::StreamClosed, $error->errorCode);
            }
        }

        [, $events] = $state->receive(
            new HeadersFrame(3, $encoder->encode([...$request, ...$fields]), true, true)->toRaw(),
        );
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals([...$request, ...$fields], $events[0]->headers);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function states(): iterable
    {
        yield 'half closed HEADERS' => [false, false];
        yield 'half closed CONTINUATION' => [false, true];
        yield 'reserved HEADERS' => [true, false];
        yield 'reserved CONTINUATION' => [true, true];
    }
}
