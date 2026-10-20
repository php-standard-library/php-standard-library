<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Frame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\H2\StreamState;
use Psl\HPACK\Header;

use function str_repeat;
use function strlen;

final class OutboundFrameSizeTest extends TestCase
{
    /** @return iterable<string, array{bool, int}> */
    public static function frameSizes(): iterable
    {
        foreach ([false, true] as $encoded) {
            foreach ([16_384, 32_768, 16_777_215] as $limit) {
                yield ($encoded ? 'encoded ' : 'raw ') . $limit => [$encoded, $limit];
            }
        }
    }

    #[DataProvider('frameSizes')]
    public function testSplitsAtPeerLimit(bool $encoded, int $limit): void
    {
        $sm = new StateMachine(true);
        $sm->receive(
            new SettingsFrame([
                Setting::MaxFrameSize->value => $limit,
                Setting::InitialWindowSize->value => 33_554_432,
            ], false)->toRaw(),
        );
        $sm->receive(new WindowUpdateFrame(0, 33_554_432)->toRaw());
        $sm->sendHeadersEncoded(1, [new Header(':method', 'POST')]);
        $before = $sm->availableSendWindow(1);
        $payload = str_repeat('x', $limit + 1);
        $wire = '';
        if ($encoded) {
            $wire = $sm->sendDataEncoded(1, $payload, true);
        } else {
            foreach ($sm->sendData(1, $payload, true) as $frame) {
                $wire .= Frame\encode($frame);
            }
        }

        [$first, $offset] = Frame\decode($wire);
        [$last, $end] = Frame\decode($wire, $offset);
        static::assertInstanceOf(RawFrame::class, $last);
        static::assertSame($limit, strlen($first->payload));
        static::assertSame(0, $first->flags);
        static::assertSame('x', $last->payload);
        static::assertSame(1, $last->flags);
        static::assertSame($payload, $first->payload . $last->payload);
        static::assertSame(strlen($wire), $end);
        static::assertSame($before - strlen($payload), $sm->availableSendWindow(1));
        static::assertSame(StreamState::HalfClosedLocal, $sm->getStreamState(1));
    }
}
