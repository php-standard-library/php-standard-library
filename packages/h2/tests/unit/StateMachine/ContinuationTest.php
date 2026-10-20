<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Event\PushPromiseReceived;
use Psl\H2\Frame;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function ord;
use function str_repeat;
use function strlen;
use function substr;

final class ContinuationTest extends TestCase
{
    public function testHeadersWithContinuation(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header('x-custom', 'value'),
        ]);

        $half = (int) (strlen($block) / 2);
        $firstPart = substr($block, 0, $half);
        $secondPart = substr($block, $half);

        $headersRaw = new HeadersFrame(1, $firstPart, true, false)->toRaw();
        [$frames, $events] = $sm->receive($headersRaw);
        static::assertSame([], $events);

        $contRaw = new ContinuationFrame(1, $secondPart, true)->toRaw();
        [$frames, $events] = $sm->receive($contRaw);

        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertTrue($events[0]->endStream);
    }

    public function testHeadersWithMultipleContinuationFrames(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header('x-one', 'value1'),
            new Header('x-two', 'value2'),
        ]);

        $third = (int) (strlen($block) / 3);
        $part1 = substr($block, 0, $third);
        $part2 = substr($block, $third, $third);
        $part3 = substr($block, $third * 2);

        $headersRaw = new HeadersFrame(1, $part1, false, false)->toRaw();
        $sm->receive($headersRaw);

        $cont1 = new ContinuationFrame(1, $part2, false)->toRaw();
        [$frames, $events] = $sm->receive($cont1);
        static::assertSame([], $events);

        $cont2 = new ContinuationFrame(1, $part3, true)->toRaw();
        [$frames, $events] = $sm->receive($cont2);

        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    public function testPushPromiseWithContinuation(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $encoder = new Encoder();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded(
            $streamId,
            [
                new Header(':method', 'GET'),
                new Header(':path', '/'),
                new Header(':scheme', 'https'),
            ],
            true,
        );

        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':path', '/pushed'),
            new Header(':scheme', 'https'),
            new Header(':authority', 'example.com'),
            new Header('x-extra', 'data'),
        ]);

        $half = (int) (strlen($block) / 2);
        $firstPart = substr($block, 0, $half);
        $secondPart = substr($block, $half);

        $pushRaw = new PushPromiseFrame($streamId, 2, $firstPart, false)->toRaw();
        [$_, $events] = $sm->receive($pushRaw);
        static::assertSame([], $events);

        $contRaw = new ContinuationFrame($streamId, $secondPart, true)->toRaw();
        [$_, $events] = $sm->receive($contRaw);

        static::assertCount(1, $events);
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);
        static::assertSame(2, $events[0]->promisedStreamId);
    }

    public function testSendHeadersEncodedWithContinuationFrames(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $settingsFrame = new SettingsFrame([Setting::MaxFrameSize->value => 16_384], false)->toRaw();
        $sm->receive($settingsFrame);

        $streamId = $sm->nextStreamId();

        $largeHeaders = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        for ($i = 0; $i < 1000; $i++) {
            $largeHeaders[] = new Header('x-hdr-' . $i, str_repeat('v', 40));
        }

        $result = $sm->sendHeadersEncoded($streamId, $largeHeaders);

        static::assertNotEmpty($result);

        $frameType = ord($result[3]) & 0xFF;
        static::assertSame(0x01, $frameType);

        $flags = ord($result[4]);
        static::assertSame(0, $flags & 0x04);

        static::assertGreaterThan((16_384 * 2) + 18, strlen($result));
    }

    public function testSendHeadersEncodedWithEndStreamAndContinuation(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $settingsFrame = new SettingsFrame([Setting::MaxFrameSize->value => 16_384], false)->toRaw();
        $sm->receive($settingsFrame);

        $streamId = $sm->nextStreamId();

        $largeHeaders = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        for ($i = 0; $i < 1000; $i++) {
            $largeHeaders[] = new Header('x-hdr-' . $i, str_repeat('v', 40));
        }

        $result = $sm->sendHeadersEncoded($streamId, $largeHeaders, true);

        static::assertNotEmpty($result);

        $flags = ord($result[4]);
        static::assertSame(0x01, $flags & 0x01);
        static::assertSame(0, $flags & 0x04);
    }
}
