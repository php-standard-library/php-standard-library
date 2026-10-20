<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class ClientContentLengthTest extends TestCase
{
    private function createClient(string $method = 'GET'): StateMachine
    {
        $client = new StateMachine(true);
        $headers = $method === 'CONNECT'
            ? [new Header(':method', $method), new Header(':authority', 'example.com:443')]
            : [new Header(':method', $method), new Header(':scheme', 'https'), new Header(':path', '/')];
        $client->sendHeadersEncoded(1, $headers, true);

        return $client;
    }

    private function response(Encoder $encoder, string $length, string $status = '200', bool $end = false): HeadersFrame
    {
        return new HeadersFrame(
            1,
            $encoder->encode([new Header(':status', $status), new Header('content-length', $length)]),
            $end,
            true,
        );
    }

    public function testExcessResponseDataIsRejectedBeforeEndStream(): void
    {
        $client = $this->createClient();
        $client->receive($this->response(new Encoder(), '2')->toRaw());

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive(new DataFrame(1, 'abc', false)->toRaw());
    }

    public function testShortResponseIsRejectedAtEndStream(): void
    {
        $client = $this->createClient();
        $client->receive($this->response(new Encoder(), '4')->toRaw());

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive(new DataFrame(1, 'abc', true)->toRaw());
    }

    public function testHeadersEndingResponseMustMatchContentLength(): void
    {
        $client = $this->createClient();

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive($this->response(new Encoder(), '1', end: true)->toRaw());
    }

    public function testTrailersEndingResponseMustMatchContentLength(): void
    {
        $client = $this->createClient();
        $encoder = new Encoder();
        $client->receive($this->response($encoder, '4')->toRaw());
        $client->receive(new DataFrame(1, 'abc', false)->toRaw());

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive(
            new HeadersFrame(1, $encoder->encode([new Header('x-trailer', 'value')]), true, true)->toRaw(),
        );
    }

    public function testResponseLengthIncludesAllDataFrames(): void
    {
        $client = $this->createClient();
        $client->receive($this->response(new Encoder(), '5')->toRaw());
        $client->receive(new DataFrame(1, 'ab', false)->toRaw());
        [, $events] = $client->receive(new DataFrame(1, 'cde', true)->toRaw());

        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame('cde', $events[0]->data);
    }

    #[DataProvider('bodylessResponses')]
    public function testBodylessResponseIgnoresDeclaredLength(string $method, string $status): void
    {
        $client = $this->createClient($method);
        [, $events] = $client->receive($this->response(new Encoder(), '100', $status, true)->toRaw());

        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function bodylessResponses(): array
    {
        return [['HEAD', '200'], ['GET', '204'], ['GET', '304']];
    }

    public function testInformationalLengthDoesNotLimitFinalResponse(): void
    {
        $client = $this->createClient();
        $encoder = new Encoder();
        $client->receive($this->response($encoder, '1', '103')->toRaw());
        $client->receive(new HeadersFrame(1, $encoder->encode([new Header(':status', '200')]), false, true)->toRaw());
        [, $events] = $client->receive(new DataFrame(1, 'abc', true)->toRaw());

        static::assertInstanceOf(DataReceived::class, $events[0]);
    }

    public function testFinalResponseAfterInformationalResponseValidatesLength(): void
    {
        $client = $this->createClient();
        $encoder = new Encoder();
        $client->receive(new HeadersFrame(1, $encoder->encode([new Header(':status', '103')]), false, true)->toRaw());
        $client->receive($this->response($encoder, '4')->toRaw());

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive(new DataFrame(1, 'abc', true)->toRaw());
    }

    public function testConnectTunnelIgnoresDeclaredLength(): void
    {
        $client = $this->createClient('CONNECT');
        $client->receive($this->response(new Encoder(), '0')->toRaw());
        [, $events] = $client->receive(new DataFrame(1, 'tunnel bytes', true)->toRaw());

        static::assertInstanceOf(DataReceived::class, $events[0]);
    }

    public function testFailedConnectValidatesResponseLength(): void
    {
        $client = $this->createClient('CONNECT');
        $client->receive($this->response(new Encoder(), '4', '403')->toRaw());

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive(new DataFrame(1, 'abc', true)->toRaw());
    }

    public function testPushedHeadResponseIgnoresDeclaredLength(): void
    {
        $client = $this->createClient();
        $encoder = new Encoder();
        $client->receive(
            new PushPromiseFrame(
                1,
                2,
                $encoder->encode([
                    new Header(':method', 'HEAD'),
                    new Header(':scheme', 'https'),
                    new Header(':path', '/pushed'),
                ]),
                true,
            )->toRaw(),
        );
        [, $events] = $client->receive(
            new HeadersFrame(
                2,
                $encoder->encode([
                    new Header(':status', '200'),
                    new Header('content-length', '100'),
                ]),
                true,
                true,
            )->toRaw(),
        );

        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    public function testPushedGetResponseValidatesDeclaredLength(): void
    {
        $client = $this->createClient();
        $encoder = new Encoder();
        $client->receive(
            new PushPromiseFrame(
                1,
                2,
                $encoder->encode([
                    new Header(':method', 'GET'),
                    new Header(':scheme', 'https'),
                    new Header(':path', '/pushed'),
                ]),
                true,
            )->toRaw(),
        );
        $client->receive(
            new HeadersFrame(
                2,
                $encoder->encode([
                    new Header(':status', '200'),
                    new Header('content-length', '4'),
                ]),
                false,
                true,
            )->toRaw(),
        );

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('content-length mismatch');
        $client->receive(new DataFrame(2, 'abc', true)->toRaw());
    }
}
