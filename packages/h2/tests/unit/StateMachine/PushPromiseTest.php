<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\Event\PushPromiseReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class PushPromiseTest extends TestCase
{
    public function testReceivePushPromise(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':path', '/push'),
            new Header(':scheme', 'https'),
        ]);

        $raw = new PushPromiseFrame(1, 2, $block, true)->toRaw();
        [, $events] = $sm->receive($raw);

        static::assertCount(1, $events);
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);
        static::assertSame(1, $events[0]->streamId);
        static::assertSame(2, $events[0]->promisedStreamId);
        static::assertCount(3, $events[0]->headers);
    }

    public function testPushPromiseDisabledThrows(): void
    {
        $sm = new StateMachine(true, [Setting::EnablePush->value => 0]);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([new Header(':method', 'GET')]);

        $this->expectException(ProtocolException::class);

        $raw = new PushPromiseFrame(1, 2, $block, true)->toRaw();
        $sm->receive($raw);
    }
}
