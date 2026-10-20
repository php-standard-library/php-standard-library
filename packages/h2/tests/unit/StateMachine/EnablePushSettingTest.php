<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;

final class EnablePushSettingTest extends TestCase
{
    /** @return iterable<string, array{bool, int, bool}> */
    public static function settings(): iterable
    {
        yield 'server disables' => [true, 0, true];
        yield 'server enables' => [true, 1, false];
        yield 'invalid server value' => [true, 2, false];
        yield 'client disables' => [false, 0, true];
        yield 'client enables' => [false, 1, true];
        yield 'invalid client value' => [false, 2, false];
    }

    #[DataProvider('settings')]
    public function testPeerRoleAndValue(bool $isClient, int $value, bool $valid): void
    {
        $sm = new StateMachine($isClient);
        try {
            [$frames] = $sm->receive(new SettingsFrame([Setting::EnablePush->value => $value], false)->toRaw());
            static::assertTrue($valid, 'Invalid ENABLE_PUSH accepted');
            static::assertTrue(SettingsFrame::fromRaw($frames[0])->ack);
        } catch (ProtocolException $e) {
            static::assertFalse($valid);
            static::assertSame(ErrorCode::ProtocolError, $e->errorCode);
        }
    }
}
