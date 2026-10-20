<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Frame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Decoder;
use Psl\HPACK\Header;
use Psl\HPACK\Internal\IntegerCodec;

final class HeaderTableSettingTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function sizes(): iterable
    {
        yield 'zero' => [0, 0];
        yield 'below cap' => [8_192, 8_192];
        yield 'above cap' => [1_048_577, 1_048_576];
        yield 'uint32 max' => [4_294_967_295, 1_048_576];
    }

    #[DataProvider('sizes')]
    public function testAcceptsPeerLimitAndCapsEncoder(int $advertised, int $used): void
    {
        $sm = new StateMachine(true);
        [$frames, $events] = $sm->receive(
            new SettingsFrame([Setting::HeaderTableSize->value => $advertised], false)->toRaw(),
        );
        static::assertTrue(SettingsFrame::fromRaw($frames[0])->ack);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame($advertised, $events[0]->settings[Setting::HeaderTableSize->value]);
        [$raw] = Frame\decode($sm->sendHeadersEncoded(1, [new Header(':method', 'GET')]));
        $block = HeadersFrame::fromRaw($raw)->headerBlockFragment;
        [$size] = IntegerCodec::decode($block, 0, 5);
        static::assertSame($used, $size);
        $headers = new Decoder($used)->decode($block);
        static::assertSame(':method', $headers[0]->name);
        static::assertSame('GET', $headers[0]->value);
    }
}
