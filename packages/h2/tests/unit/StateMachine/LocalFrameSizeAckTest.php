<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\Configuration;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;

use function str_repeat;

final class LocalFrameSizeAckTest extends TestCase
{
    public function testPeerSettingsDoNotActivatePendingReduction(): void
    {
        $sm = self::initialized(32_768);
        $sm->sendSettings([Setting::MaxFrameSize->value => 16_384]);
        $sm->receive(new SettingsFrame([], false)->toRaw());
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
        self::ack($sm);
        self::assertRejected($sm, 16_385);
    }

    public function testReductionTakesEffectOnAck(): void
    {
        $sm = self::initialized(32_768);
        $sm->sendSettings([Setting::MaxFrameSize->value => 16_384]);
        self::ack($sm);
        self::assertRejected($sm, 16_385);
    }

    public function testEachSettingsFrameUsesOneAck(): void
    {
        $sm = new StateMachine(true, [Setting::MaxFrameSize->value => 32_768]);
        $sm->initialize();
        $sm->sendSettings([]);
        $sm->sendSettings([Setting::MaxFrameSize->value => 24_576]);
        $sm->sendSettings([Setting::MaxFrameSize->value => 16_384]);
        self::ack($sm);
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
        self::ack($sm);
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
        self::ack($sm);
        static::assertSame([[], []], $sm->receive(self::frame(24_576)));
        self::assertRejected($sm, 24_577);
        self::ack($sm);
        self::assertRejected($sm, 16_385);
    }

    public function testAdvertisedIncreaseIsAcceptedBeforeAck(): void
    {
        $sm = self::initialized(16_384);
        $sm->sendSettings([Setting::MaxFrameSize->value => 32_768]);
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
        self::ack($sm);
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
    }

    public function testEarlierAckDoesNotDiscardPendingIncrease(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $sm->sendSettings([Setting::MaxFrameSize->value => 32_768]);
        $sm->sendSettings([Setting::MaxFrameSize->value => 16_384]);
        self::ack($sm);
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
        self::ack($sm);
        static::assertSame([[], []], $sm->receive(self::frame(32_768)));
        self::ack($sm);
        self::assertRejected($sm, 16_385);
    }

    public function testTransportRejectsFrameAfterReductionAckWithoutReadingPayload(): void
    {
        $ack = Frame\encode(new SettingsFrame([], true)->toRaw());
        $handle = new ChunkedHandle(
            $ack . Frame\encode(self::frame(32_768)) . $ack . Frame\encode(self::frame(16_385)),
            65_536,
        );
        $client = new ClientConnection($handle, new Configuration(settings: [Setting::MaxFrameSize->value => 32_768]));
        $client->initialize();
        $client->readEvent();
        $client->sendSettings([Setting::MaxFrameSize->value => 16_384]);
        static::assertSame([], $client->readEvent());
        $client->readEvent();
        try {
            $client->readEvent();
            static::fail('Expected a frame-size error after ACK.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::FrameSizeError, $error->errorCode);
        }

        static::assertLessThanOrEqual(32_768 + (4 * 9) + 4096, $handle->readPos);
    }

    private static function initialized(int $limit): StateMachine
    {
        $sm = new StateMachine(true, [Setting::MaxFrameSize->value => $limit]);
        $sm->initialize();
        self::ack($sm);

        return $sm;
    }

    private static function ack(StateMachine $sm): void
    {
        $sm->receive(new SettingsFrame([], true)->toRaw());
    }

    private static function frame(int $size): RawFrame
    {
        return new RawFrame(255, 0, 0, str_repeat('x', $size));
    }

    private static function assertRejected(StateMachine $sm, int $size): void
    {
        try {
            $sm->receive(self::frame($size));
            static::fail('Expected a frame-size error.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::FrameSizeError, $error->errorCode);
        }
    }
}
