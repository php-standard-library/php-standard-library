<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;

final class SettingsTest extends TestCase
{
    public function testInitializeReturnsSettingsFrame(): void
    {
        $sm = new StateMachine(true);
        $frames = $sm->initialize();

        static::assertCount(1, $frames);
        static::assertSame(FrameType::Settings->value, $frames[0]->type);
    }

    public function testReceiveSettingsReturnsAck(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        [$responseFrames, $events] = $sm->receive($settingsFrame);

        static::assertCount(1, $responseFrames);
        static::assertSame(FrameType::Settings->value, $responseFrames[0]->type);
        static::assertSame(0x01, $responseFrames[0]->flags & 0x01);

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame(100, $events[0]->settings[Setting::MaxConcurrentStreams->value]);
    }

    public function testReceiveSettingsAck(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $ackFrame = new SettingsFrame([], true)->toRaw();
        [$responseFrames, $events] = $sm->receive($ackFrame);

        static::assertSame([], $responseFrames);
        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testInvalidEnablePushValue(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $this->expectException(ProtocolException::class);

        $frame = new SettingsFrame([Setting::EnablePush->value => 2], false)->toRaw();
        $sm->receive($frame);
    }

    public function testInvalidInitialWindowSize(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $this->expectException(FlowControlException::class);

        $frame = new SettingsFrame([Setting::InitialWindowSize->value => 2_147_483_648], false)->toRaw();
        $sm->receive($frame);
    }

    public function testInvalidMaxFrameSize(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $this->expectException(ProtocolException::class);

        $frame = new SettingsFrame([Setting::MaxFrameSize->value => 100], false)->toRaw();
        $sm->receive($frame);
    }

    public function testCustomLocalSettings(): void
    {
        $sm = new StateMachine(true, [
            Setting::MaxConcurrentStreams->value => 50,
        ]);

        $frames = $sm->initialize();
        $parsed = SettingsFrame::fromRaw($frames[0]);
        static::assertSame(50, $parsed->settings[Setting::MaxConcurrentStreams->value]);
    }

    public function testWindowSizeAdjustmentOnSettingsChange(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')]);

        $window1 = $sm->availableSendWindow($streamId);

        $frame = new SettingsFrame([Setting::InitialWindowSize->value => 32_768], false)->toRaw();
        $sm->receive($frame);

        $window2 = $sm->availableSendWindow($streamId);

        static::assertNotSame($window1, $window2);
    }

    public function testUnknownSettingIsIgnored(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $frame = new SettingsFrame([0xFF => 42], false)->toRaw();
        [$responseFrames, $events] = $sm->receive($frame);

        static::assertCount(1, $responseFrames);
        static::assertCount(1, $events);
    }

    public function testNewStreamUsesUpdatedInitialWindowSize(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $newWindowSize = 32_768;
        $frame = new SettingsFrame([Setting::InitialWindowSize->value => $newWindowSize], false)->toRaw();
        $sm->receive($frame);

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')]);

        $window = $sm->availableSendWindow($streamId);
        static::assertSame($newWindowSize, $window);
    }

    public function testMaxHeaderListSizeUpdatedFromRemoteSettings(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $frame = new SettingsFrame([Setting::MaxHeaderListSize->value => 128], false)->toRaw();
        $sm->receive($frame);

        $streamId = $sm->nextStreamId();

        $this->expectException(ProtocolException::class);

        $sm->sendHeadersEncoded($streamId, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header('x-large', str_repeat('A', 200)),
        ]);
    }

    public function testMaxHeaderListSizeLargeEnoughSucceeds(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $frame = new SettingsFrame([Setting::MaxHeaderListSize->value => 16_384], false)->toRaw();
        $sm->receive($frame);

        $streamId = $sm->nextStreamId();

        $frames = $sm->sendHeadersEncoded($streamId, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        static::assertNotEmpty($frames);
    }

    public function testHeaderTableSizeUpdatedFromRemoteSettings(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $frame = new SettingsFrame([Setting::HeaderTableSize->value => 2048], false)->toRaw();
        [$responseFrames, $events] = $sm->receive($frame);

        static::assertCount(1, $responseFrames);
        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testLargeHeaderTableSizeIsAccepted(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $frame = new SettingsFrame([Setting::HeaderTableSize->value => 2_000_000], false)->toRaw();
        [$frames] = $sm->receive($frame);
        static::assertTrue(SettingsFrame::fromRaw($frames[0])->ack);
    }

    public function testMaxConcurrentStreamsEnforcedOnReceivedHeaders(): void
    {
        $sm = new StateMachine(false, [Setting::MaxConcurrentStreams->value => 2]);
        $sm->initialize();

        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 2], false)->toRaw();
        $sm->receive($settingsFrame);

        $encoder = new Encoder();
        $block1 = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $sm->receive(new HeadersFrame(1, $block1, false, true)->toRaw());

        $encoder2 = new Encoder();
        $block2 = $encoder2->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $sm->receive(new HeadersFrame(3, $block2, false, true)->toRaw());

        $s1 = $sm->nextStreamId();

        $this->expectException(FlowControlException::class);

        $sm->sendHeadersEncoded($s1, [new Header(':status', '200')]);
    }
}
