<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Frame;

use PHPUnit\Framework\TestCase;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Setting;

use function pack;

final class SettingsPairsTest extends TestCase
{
    public function testDuplicateSettingsRoundTrip(): void
    {
        $payload = pack('nNnN', Setting::EnablePush->value, 2, Setting::EnablePush->value, 0);
        $frame = SettingsFrame::fromRaw(new RawFrame(FrameType::Settings->value, 0, 0, $payload));

        static::assertSame($payload, $frame->toRaw()->payload);
        static::assertSame([Setting::EnablePush->value => 0], $frame->settings);
        static::assertSame([[Setting::EnablePush->value, 2], [Setting::EnablePush->value, 0]], $frame->settingPairs);
    }

    public function testMapConstructorBuildsSettingPairs(): void
    {
        $frame = new SettingsFrame([Setting::EnablePush->value => 0], false);

        static::assertSame([[Setting::EnablePush->value, 0]], $frame->settingPairs);
    }

    public function testPairsDetermineSettingsMap(): void
    {
        $frame = new SettingsFrame([], false, [[Setting::EnablePush->value, 1], [Setting::EnablePush->value, 0]]);

        static::assertSame([Setting::EnablePush->value => 0], $frame->settings);
        static::assertSame(
            pack('nNnN', Setting::EnablePush->value, 1, Setting::EnablePush->value, 0),
            $frame->toRaw()->payload,
        );
    }
}
