<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H1;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Client\Internal\H1\H1Connection;
use Psl\HTTP\Client\Internal\H1\Transport;
use Psl\HTTP\Client\Tests\Fixture\H1\FakeStream;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\URL;

use function strlen;

final class ExpectContinueBugTest extends TestCase
{
    public function testExpectContinueMustSendTrailers(): void
    {
        $body = 'payload';
        $url = URL\parse('http://127.0.0.1:8080/');

        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            headers: FieldMap::from([
                ['expect', '100-continue'],
            ]),
            body: new IO\MemoryHandle($body),
            trailers: Async\Awaitable::complete(FieldMap::from([
                ['x-checksum', 'abc123'],
            ])),
        );

        $stream = new FakeStream("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 2\r\n\r\nok");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));

        Transport::exchange($connection, $request, $url, new ClientConfiguration());

        static::assertStringContainsString(
            'x-checksum: abc123',
            $stream->written,
            'Trailers must be written when Expect: 100-continue is used',
        );
    }

    public function testExpectContinueWithoutContentLengthMustUseChunkedEncoding(): void
    {
        $body = 'payload';
        $url = URL\parse('http://127.0.0.1:8080/');

        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            headers: FieldMap::from([
                ['expect', '100-continue'],
            ]),
            body: new IO\MemoryHandle($body),
        );

        $stream = new FakeStream("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 2\r\n\r\nok");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));

        Transport::exchange($connection, $request, $url, new ClientConfiguration());

        static::assertStringContainsString(
            'transfer-encoding: chunked',
            $stream->written,
            'Expect: 100-continue with no Content-Length must use chunked encoding',
        );
    }

    public function testExpectContinueBodyMustBeChunkedEncoded(): void
    {
        $body = 'payload';
        $url = URL\parse('http://127.0.0.1:8080/');

        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            headers: FieldMap::from([
                ['expect', '100-continue'],
            ]),
            body: new IO\MemoryHandle($body),
        );

        $stream = new FakeStream("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 2\r\n\r\nok");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));

        Transport::exchange($connection, $request, $url, new ClientConfiguration());

        $written = $stream->written;

        static::assertStringContainsString(
            "7\r\npayload\r\n",
            $written,
            'Body must use chunked framing with Expect: 100-continue',
        );

        static::assertStringContainsString("0\r\n", $written, 'Chunked body must end with a zero-length chunk');
    }

    public function testExpectContinueWithContentLengthBodyIsCorrect(): void
    {
        $body = 'payload';
        $url = URL\parse('http://127.0.0.1:8080/');

        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            headers: FieldMap::from([
                ['expect', '100-continue'],
                ['content-length', (string) strlen($body)],
            ]),
            body: new IO\MemoryHandle($body),
        );

        $stream = new FakeStream("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 2\r\n\r\nok");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));

        [$tx, $_] = Transport::exchange($connection, $request, $url, new ClientConfiguration());

        static::assertSame(200, $tx->response->status);

        static::assertStringContainsString("content-length: 7\r\n", $stream->written);
        static::assertStringContainsString('payload', $stream->written);
    }

    public function testExpectContinueChecksContentLength(): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            headers: FieldMap::from([
                ['expect',         '100-continue'],
                ['content-length', '6'],
            ]),
            body: new IO\MemoryHandle('hello'),
        );
        $stream = new FakeStream("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        $this->expectException(RequestException::class);
        Transport::exchange($connection, $request, $url, new ClientConfiguration());
    }
}
