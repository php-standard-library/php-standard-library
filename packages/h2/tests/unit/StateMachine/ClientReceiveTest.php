<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Event\PushPromiseReceived;
use Psl\H2\Frame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class ClientReceiveTest extends TestCase
{
    public function testPushStreamReceivesHeadersThenData(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $sm->sendHeadersEncoded(1, [new Header(':method', 'GET')], true);
        $encoder = new Encoder();

        $pushPromiseBlock = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':path', '/pushed'),
            new Header(':scheme', 'https'),
            new Header(':authority', 'example.com'),
        ]);
        $raw = new PushPromiseFrame(1, 2, $pushPromiseBlock, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(1, $events);
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);

        $responseBlock = $encoder->encode([
            new Header(':status', '200'),
            new Header('content-type', 'text/plain'),
        ]);
        $raw = new HeadersFrame(2, $responseBlock, false, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertSame(2, $events[0]->streamId);

        $raw = new DataFrame(2, 'hello pushed', true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame('hello pushed', $events[0]->data);
        static::assertTrue($events[0]->endStream);
    }

    public function testPushStreamHeadersWithEndStream(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $sm->sendHeadersEncoded(1, [new Header(':method', 'GET')], true);
        $encoder = new Encoder();

        $pushPromiseBlock = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':path', '/empty-push'),
            new Header(':scheme', 'https'),
            new Header(':authority', 'example.com'),
        ]);
        $raw = new PushPromiseFrame(1, 2, $pushPromiseBlock, true)->toRaw();
        $sm->receive($raw);

        $responseBlock = $encoder->encode([
            new Header(':status', '204'),
        ]);
        $raw = new HeadersFrame(2, $responseBlock, true, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(2, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertTrue($events[0]->endStream);
    }

    public function testInformationalResponseFollowedByFinalResponse(): void
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

        $infoBlock = $encoder->encode([
            new Header(':status', '102'),
            new Header('x-info', 'processing'),
        ]);
        $raw = new HeadersFrame($streamId, $infoBlock, false, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertFalse($events[0]->endStream);

        $finalBlock = $encoder->encode([
            new Header(':status', '200'),
            new Header('content-type', 'text/plain'),
        ]);
        $raw = new HeadersFrame($streamId, $finalBlock, false, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    public function testMultipleInformationalResponsesFollowedByFinal(): void
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

        for ($i = 0; $i < 3; $i++) {
            $infoBlock = $encoder->encode([
                new Header(':status', '100'),
            ]);
            $raw = new HeadersFrame($streamId, $infoBlock, false, true)->toRaw();
            [, $events] = $sm->receive($raw);
            static::assertCount(1, $events);
            static::assertInstanceOf(HeadersReceived::class, $events[0]);
        }

        $finalBlock = $encoder->encode([
            new Header(':status', '200'),
            new Header('content-length', '5'),
        ]);
        $raw = new HeadersFrame($streamId, $finalBlock, false, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);

        $raw = new DataFrame($streamId, 'hello', true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertTrue($events[0]->endStream);
    }

    public function testInformationalThenFinalThenTrailers(): void
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

        $infoBlock = $encoder->encode([
            new Header(':status', '102'),
        ]);
        $raw = new HeadersFrame($streamId, $infoBlock, false, true)->toRaw();
        $sm->receive($raw);

        $finalBlock = $encoder->encode([
            new Header(':status', '200'),
        ]);
        $raw = new HeadersFrame($streamId, $finalBlock, false, true)->toRaw();
        $sm->receive($raw);

        $raw = new DataFrame($streamId, 'body', false)->toRaw();
        $sm->receive($raw);

        $trailerBlock = $encoder->encode([
            new Header('x-checksum', 'abc'),
        ]);
        $raw = new HeadersFrame($streamId, $trailerBlock, true, true)->toRaw();
        [, $events] = $sm->receive($raw);
        static::assertCount(2, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertTrue($events[0]->endStream);
    }
}
