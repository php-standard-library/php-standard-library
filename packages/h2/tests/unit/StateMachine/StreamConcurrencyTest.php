<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class StreamConcurrencyTest extends TestCase
{
    /**
     * @return list<Header>
     */
    private function requestHeaders(): array
    {
        return [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':authority', 'example.com'),
            new Header(':path', '/'),
        ];
    }

    public function testLocalStreamsDoNotConsumePeerLimit(): void
    {
        $client = new StateMachine(true, [Setting::MaxConcurrentStreams->value => 1]);
        $client->sendHeadersEncoded(1, $this->requestHeaders(), true);
        $encoder = new Encoder();
        $client->receive(new PushPromiseFrame(1, 2, $encoder->encode($this->requestHeaders()), true)->toRaw());

        [$frames] = $client->receive(
            new HeadersFrame(2, $encoder->encode([new Header(':status', '200')]), false, true)->toRaw(),
        );

        static::assertSame([], $frames);
        static::assertSame(2, $client->activeStreamCount());
    }

    public function testPeerStreamsDoNotConsumeLocalLimit(): void
    {
        $server = new StateMachine(false);
        $server->receive(new SettingsFrame([Setting::MaxConcurrentStreams->value => 1], false)->toRaw());
        $server->receive(new HeadersFrame(1, new Encoder()->encode($this->requestHeaders()), true, true)->toRaw());
        $server->sendPushPromise(1, 2, $this->requestHeaders());
        $server->sendResponseHeadersEncoded(2, '200', []);

        static::assertSame(2, $server->activeStreamCount());
    }

    public function testReservedLocalStreamsCountOnlyWhenActivated(): void
    {
        $server = new StateMachine(false);
        $server->receive(new SettingsFrame([Setting::MaxConcurrentStreams->value => 1], false)->toRaw());
        $server->receive(new HeadersFrame(1, new Encoder()->encode($this->requestHeaders()), true, true)->toRaw());
        $server->sendPushPromise(1, 2, $this->requestHeaders());
        $server->sendPushPromise(1, 4, $this->requestHeaders());
        static::assertSame(1, $server->activeStreamCount());

        $server->sendResponseHeadersEncoded(2, '200', []);
        static::assertSame(2, $server->activeStreamCount());

        $this->expectException(FlowControlException::class);
        $server->sendResponseHeadersEncoded(4, '200', []);
    }

    public function testReservedRemoteStreamsCountOnlyWhenActivated(): void
    {
        $client = new StateMachine(true, [Setting::MaxConcurrentStreams->value => 1]);
        $client->sendHeadersEncoded(1, $this->requestHeaders(), true);
        $encoder = new Encoder();
        $client->receive(new PushPromiseFrame(1, 2, $encoder->encode($this->requestHeaders()), true)->toRaw());
        $client->receive(new PushPromiseFrame(1, 4, $encoder->encode($this->requestHeaders()), true)->toRaw());
        static::assertSame(1, $client->activeStreamCount());

        $client->receive(new HeadersFrame(2, $encoder->encode([new Header(':status', '200')]), false, true)->toRaw());
        static::assertSame(2, $client->activeStreamCount());

        [$frames] = $client->receive(
            new HeadersFrame(4, $encoder->encode([new Header(':status', '200')]), false, true)->toRaw(),
        );
        static::assertCount(1, $frames);
        static::assertSame(ErrorCode::RefusedStream, RstStreamFrame::fromRaw($frames[0])->errorCode);
        static::assertSame(2, $client->activeStreamCount());
    }

    public function testClosingReservedStreamDoesNotFreeActiveStreamSlot(): void
    {
        $client = new StateMachine(true);
        $client->sendHeadersEncoded(1, $this->requestHeaders(), true);
        $client->receive(new PushPromiseFrame(1, 2, new Encoder()->encode($this->requestHeaders()), true)->toRaw());
        $client->resetStream(2, ErrorCode::Cancel);

        static::assertSame(1, $client->activeStreamCount());
    }

    public function testClosingPushFreesOnlyPeerStreamSlot(): void
    {
        $client = new StateMachine(true, [Setting::MaxConcurrentStreams->value => 1]);
        $client->receive(new SettingsFrame([Setting::MaxConcurrentStreams->value => 1], false)->toRaw());
        $client->sendHeadersEncoded(1, $this->requestHeaders(), true);
        $encoder = new Encoder();
        $client->receive(new PushPromiseFrame(1, 2, $encoder->encode($this->requestHeaders()), true)->toRaw());
        $client->receive(new HeadersFrame(2, $encoder->encode([new Header(':status', '200')]), true, true)->toRaw());

        static::assertSame(1, $client->activeStreamCount());
        $this->expectException(FlowControlException::class);
        $client->sendHeadersEncoded(3, $this->requestHeaders(), true);
    }
}
