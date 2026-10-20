<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Header;

use function pack;

final class DuplicateSettingsTest extends TestCase
{
    #[DataProvider('invalidSettings')]
    public function testLaterValueDoesNotHideInvalidSetting(Setting $setting, int $invalid, int $valid): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $frame = new RawFrame(
            FrameType::Settings->value,
            0,
            0,
            pack('nNnN', $setting->value, $invalid, $setting->value, $valid),
        );

        $this->expectException(
            $setting === Setting::InitialWindowSize ? FlowControlException::class : ProtocolException::class,
        );
        $sm->receive($frame);
    }

    /**
     * @return iterable<string, array{Setting, int, int}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'enable push' => [Setting::EnablePush, 2, 0];
        yield 'initial window size' => [Setting::InitialWindowSize, 2_147_483_648, 65_535];
        yield 'max frame size' => [Setting::MaxFrameSize, 100, 16_384];
        yield 'enable connect protocol' => [Setting::EnableConnectProtocol, 2, 0];
    }

    public function testLastValueWinsForValidDuplicates(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')]);

        [$frames, $events] = $sm->receive(
            new RawFrame(
                FrameType::Settings->value,
                0,
                0,
                pack('nNnN', Setting::InitialWindowSize->value, 0, Setting::InitialWindowSize->value, 128),
            ),
        );

        static::assertTrue(SettingsFrame::fromRaw($frames[0])->ack);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame([Setting::InitialWindowSize->value => 128], $events[0]->settings);
        static::assertSame(128, $sm->availableSendWindow($streamId));
    }

    public function testLaterWindowSizeDoesNotHideOverflow(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')]);
        $sm->receive(new WindowUpdateFrame($streamId, 2_147_418_112)->toRaw());

        $this->expectException(FlowControlException::class);
        $sm->receive(
            new RawFrame(
                FrameType::Settings->value,
                0,
                0,
                pack('nNnN', Setting::InitialWindowSize->value, 65_536, Setting::InitialWindowSize->value, 65_535),
            ),
        );
    }

    public function testHeaderTableResizeKeepsSmallestSize(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $sm->receive(
            new RawFrame(
                FrameType::Settings->value,
                0,
                0,
                pack('nNnN', Setting::HeaderTableSize->value, 0, Setting::HeaderTableSize->value, 4096),
            ),
        );

        [$frame] = Frame\decode($sm->sendHeadersEncoded($sm->nextStreamId(), [new Header(':method', 'GET')]));
        $block = HeadersFrame::fromRaw($frame)->headerBlockFragment;

        static::assertStringStartsWith("\x20\x3f\xe1\x1f", $block);
    }
}
