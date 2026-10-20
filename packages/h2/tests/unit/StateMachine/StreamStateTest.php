<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\DataReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class StreamStateTest extends TestCase
{
    public function testClientStreamIdsAreOdd(): void
    {
        $sm = new StateMachine(true);

        static::assertSame(1, $sm->nextStreamId());
        static::assertSame(3, $sm->nextStreamId());
        static::assertSame(5, $sm->nextStreamId());
    }

    public function testServerStreamIdsAreEven(): void
    {
        $sm = new StateMachine(false);

        static::assertSame(2, $sm->nextStreamId());
        static::assertSame(4, $sm->nextStreamId());
    }

    public function testSendDataOnClosedStreamThrows(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')], true);

        $this->expectException(StreamException::class);
        $sm->sendData($streamId, 'data');
    }

    public function testSendHeadersEndStreamTransitionsToHalfClosed(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')], true);

        $this->expectException(StreamException::class);
        $sm->sendData($streamId, 'should fail');
    }

    public function testReceiveDataOnIdleStreamThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $this->expectException(ProtocolException::class);

        $dataRaw = new DataFrame(1, 'data', false)->toRaw();
        $sm->receive($dataRaw);
    }

    public function testSendHeadersOnHalfClosedLocalThrows(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $streamId = $sm->nextStreamId();
        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'POST')]);
        $sm->sendData($streamId, 'body', true);

        $this->expectException(StreamException::class);

        $sm->sendHeadersEncoded($streamId, [new Header(':method', 'GET')]);
    }

    public function testReceiveDataAfterReceiveRstStreamThrows(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $sm->receive($headersRaw);

        $rstRaw = new RstStreamFrame(1, ErrorCode::Cancel)->toRaw();
        $sm->receive($rstRaw);

        $this->expectException(StreamException::class);

        $dataRaw = new DataFrame(1, 'data', false)->toRaw();
        $sm->receive($dataRaw);
    }

    public function testReceiveHeadersEndStreamOnOpenTransitionsToHalfClosedRemote(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, true, true)->toRaw();
        $sm->receive($headersRaw);

        $this->expectException(StreamException::class);

        $dataRaw = new DataFrame(1, 'more data', false)->toRaw();
        $sm->receive($dataRaw);
    }

    public function testReceiveDataEndStreamOnOpenTransitionsToHalfClosedRemote(): void
    {
        $sm = new StateMachine(false);
        $sm->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $sm->receive($headersRaw);

        $dataRaw = new DataFrame(1, 'body', true)->toRaw();
        $sm->receive($dataRaw);

        $this->expectException(StreamException::class);

        $moreData = new DataFrame(1, 'extra', false)->toRaw();
        $sm->receive($moreData);
    }

    public function testFullStreamLifecycle(): void
    {
        $server = new StateMachine(false);
        $server->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $server->receive($headersRaw);

        $dataRaw = new DataFrame(1, 'body', true)->toRaw();
        [, $events] = $server->receive($dataRaw);

        $hasDataReceived = false;
        foreach ($events as $event) {
            if (!$event instanceof DataReceived) {
                continue;
            }

            $hasDataReceived = true;
            static::assertTrue($event->endStream);
        }

        static::assertTrue($hasDataReceived);
    }
}
