<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;

final class LocalStreamSettingsAckTest extends TestCase
{
    public function testRaisedConcurrencyLimitAppliesOnAck(): void
    {
        $sm = self::server([Setting::MaxConcurrentStreams->value => 1]);
        self::assertAccepted($sm, 1);

        $sm->sendSettings([Setting::MaxConcurrentStreams->value => 2]);
        self::assertRefused($sm, 3);

        self::ack($sm);
        self::assertAccepted($sm, 5);
        self::assertRefused($sm, 7);
    }

    public function testLoweredConcurrencyLimitAppliesOnAck(): void
    {
        $sm = self::server([Setting::MaxConcurrentStreams->value => 2]);
        self::assertAccepted($sm, 1);

        $sm->sendSettings([Setting::MaxConcurrentStreams->value => 1]);
        self::assertAccepted($sm, 3);

        self::ack($sm);
        self::assertRefused($sm, 5);
    }

    public function testEachSettingsFrameUsesOneAck(): void
    {
        $sm = self::server([Setting::MaxConcurrentStreams->value => 1]);
        self::assertAccepted($sm, 1);

        $sm->sendSettings([]);
        $sm->sendSettings([Setting::MaxConcurrentStreams->value => 3]);
        self::ack($sm);
        self::assertRefused($sm, 3);

        self::ack($sm);
        self::assertAccepted($sm, 5);
        self::assertAccepted($sm, 7);
    }

    public function testRaisedInitialWindowGrowsOpenStreamsOnAck(): void
    {
        $sm = self::server([Setting::InitialWindowSize->value => 10]);
        self::assertAccepted($sm, 1, endStream: false);

        $sm->sendSettings([Setting::InitialWindowSize->value => 25]);
        [, $events] = $sm->receive(new DataFrame(1, str_repeat('a', 10), false)->toRaw());
        static::assertInstanceOf(DataReceived::class, $events[0]);

        self::ack($sm);
        [, $events] = $sm->receive(new DataFrame(1, str_repeat('b', 15), false)->toRaw());
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame(str_repeat('b', 15), $events[0]->data);
    }

    public function testRaisedInitialWindowIsNotAppliedBeforeAck(): void
    {
        $sm = self::server([Setting::InitialWindowSize->value => 10]);
        self::assertAccepted($sm, 1, endStream: false);
        $sm->sendSettings([Setting::InitialWindowSize->value => 25]);

        $this->expectException(FlowControlException::class);
        $sm->receive(new DataFrame(1, str_repeat('a', 11), false)->toRaw());
    }

    public function testRaisedInitialWindowAppliesToStreamsOpenedAfterAck(): void
    {
        $sm = self::server([Setting::InitialWindowSize->value => 10]);
        $sm->sendSettings([Setting::InitialWindowSize->value => 25]);
        self::ack($sm);

        self::assertAccepted($sm, 1, endStream: false);
        [, $events] = $sm->receive(new DataFrame(1, str_repeat('a', 25), false)->toRaw());
        static::assertInstanceOf(DataReceived::class, $events[0]);
    }

    public function testLoweredInitialWindowShrinksOpenStreamsOnAck(): void
    {
        $sm = self::server([Setting::InitialWindowSize->value => 25]);
        self::assertAccepted($sm, 1, endStream: false);
        $sm->sendSettings([Setting::InitialWindowSize->value => 10]);
        self::ack($sm);

        $this->expectException(FlowControlException::class);
        $sm->receive(new DataFrame(1, str_repeat('a', 11), false)->toRaw());
    }

    /**
     * @param array<positive-int, non-negative-int> $settings
     */
    private static function server(array $settings): StateMachine
    {
        $sm = new StateMachine(false, $settings);
        $sm->initialize();
        self::ack($sm);

        return $sm;
    }

    private static function ack(StateMachine $sm): void
    {
        $sm->receive(new SettingsFrame([], true)->toRaw());
    }

    private static function requestFrame(int $streamId, bool $endStream): HeadersFrame
    {
        return new HeadersFrame(
            $streamId,
            new Encoder()->encode([
                new Header(':method', 'GET'),
                new Header(':scheme', 'https'),
                new Header(':path', '/'),
            ]),
            $endStream,
            true,
        );
    }

    private static function assertAccepted(StateMachine $sm, int $streamId, bool $endStream = true): void
    {
        [$frames, $events] = $sm->receive(self::requestFrame($streamId, $endStream)->toRaw());
        static::assertSame([], $frames);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertSame($streamId, $events[0]->streamId);
    }

    private static function assertRefused(StateMachine $sm, int $streamId): void
    {
        [$frames, $events] = $sm->receive(self::requestFrame($streamId, true)->toRaw());
        static::assertSame([], $events);
        static::assertCount(1, $frames);
        $reset = RstStreamFrame::fromRaw($frames[0]);
        static::assertSame($streamId, $reset->streamId);
        static::assertSame(ErrorCode::RefusedStream, $reset->errorCode);
    }
}
