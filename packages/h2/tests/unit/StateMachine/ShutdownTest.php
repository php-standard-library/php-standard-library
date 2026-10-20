<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class ShutdownTest extends TestCase
{
    public function testSendHeadersBlockedAfterGoAway(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $sm->goAway(ErrorCode::NoError);

        static::assertTrue($sm->shutdown);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('GOAWAY');

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
    }

    public function testSendHeadersAllowedOnExistingStreamAfterGoAway(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded(
            $streamId,
            [
                new Header(':method', 'GET'),
                new Header(':scheme', 'https'),
                new Header(':path', '/'),
            ],
            false,
        );

        $sm->goAway(ErrorCode::NoError);

        $sm->sendData($streamId, 'data', true);

        static::assertTrue(true);
    }

    public function testReceiveGoAwaySetsShutdown(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $goaway = new GoAwayFrame(0, ErrorCode::NoError->value, '')->toRaw();
        $sm->receive($goaway);

        static::assertTrue($sm->shutdown);
    }

    public function testReceiveNewStreamAfterGoAway(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $raw1 = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw1);

        $sm->goAway(ErrorCode::NoError);

        $raw3 = new HeadersFrame(3, $block, true, true)->toRaw();
        static::assertSame([[], []], $sm->receive($raw3));
    }

    public function testGoAwayIncludesLastPeerStreamId(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $raw1 = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($raw1);

        $raw3 = new HeadersFrame(3, $block, true, true)->toRaw();
        $sm->receive($raw3);

        $frames = $sm->goAway(ErrorCode::NoError);

        static::assertCount(1, $frames);
        $parsed = GoAwayFrame::fromRaw($frames[0]);
        static::assertSame(3, $parsed->lastStreamId);
    }
}
