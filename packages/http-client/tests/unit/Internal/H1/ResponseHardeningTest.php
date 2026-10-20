<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Internal\H1\ChunkedBodyHandle;
use Psl\HTTP\Client\Internal\H1\ResponseReader;
use Psl\HTTP\Client\Internal\H1\UntilCloseBodyHandle;
use Psl\HTTP\Client\Internal\HttpTunnel;
use Psl\HTTP\Client\ProxyConfiguration;
use Psl\HTTP\Client\Tests\Fixture\H1\FakeStream;
use Psl\HTTP\Message\FieldMap;
use Psl\IO;
use Psl\TCP;
use Psl\TLS;
use Psl\URL;

use function str_repeat;

final class ResponseHardeningTest extends TestCase
{
    #[DataProvider('invalidResponses')]
    public function testInvalidResponseIsRejected(string $response): void
    {
        $this->expectException(ProtocolException::class);
        ResponseReader::readWithInformational(
            new IO\Reader(new FakeStream($response)),
            new ClientConfiguration(),
            new Async\NullCancellationToken(),
            false,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponses(): iterable
    {
        foreach (['', '-1', '+1', '1x', '9999999999999999999999999'] as $value) {
            yield 'invalid length ' . $value => ["HTTP/1.1 200 OK\r\nContent-Length: {$value}\r\n\r\n"];
        }

        yield 'space before colon' => ["HTTP/1.1 200 OK\r\nContent-Length : 0\r\n\r\n"];
        yield 'obs-fold' => ["HTTP/1.1 200 OK\r\nx-test: one\r\n folded: two\r\n\r\n"];
        yield 'too many interim responses' => [
            str_repeat("HTTP/1.1 103 Early Hints\r\n\r\n", 17) . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ];
        yield 'unsupported upgrade' => [
            "HTTP/1.1 101 Switching Protocols\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        ];
        yield 'incomplete headers' => ["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n"];
    }

    public function testTransferEncodingWithContentLengthDisablesReuse(): void
    {
        [$response, $keepAlive] = ResponseReader::read(
            new IO\Reader(
                new FakeStream(
                    "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Length: 1\r\n\r\n1\r\nx\r\n0\r\n\r\n",
                ),
            ),
            8192,
            new Async\NullCancellationToken(),
            false,
        );
        static::assertFalse($keepAlive);
        static::assertSame('x', $response->body?->readAll());
    }

    public function testCloseDelimitedBodyDisablesReuse(): void
    {
        [$_, $keepAlive] = ResponseReader::read(
            new IO\Reader(new FakeStream("HTTP/1.1 200 OK\r\n\r\nhello")),
            8192,
            new Async\NullCancellationToken(),
            false,
        );
        static::assertFalse($keepAlive);
    }

    public function testCloseDelimitedBodyPropagatesReadErrors(): void
    {
        $stream = static::createStub(IO\ReadHandleInterface::class);
        $stream->method('read')->willThrowException(new IO\Exception\RuntimeException('read failed'));
        $handle = new UntilCloseBodyHandle(new IO\Reader($stream));
        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('read failed');
        $handle->read();
    }

    #[DataProvider('invalidChunks')]
    public function testInvalidChunkMetadataIsRejected(string $body): void
    {
        /** @var Async\Deferred<FieldMap> $trailers */
        $trailers = new Async\Deferred();
        $handle = new ChunkedBodyHandle(new IO\Reader(new FakeStream($body)), $trailers);
        $this->expectException(ProtocolException::class);
        $handle->readAll();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidChunks(): iterable
    {
        yield 'size line limit' => [str_repeat('0', 8193) . "\r\n\r\n"];
        yield 'extension limit' => ['0;x=' . str_repeat('a', 8193) . "\r\n\r\n"];
        yield 'trailer line limit' => ["0\r\nx: " . str_repeat('a', 8193) . "\r\n\r\n"];
        yield 'total trailer limit' => ["0\r\n" . str_repeat('x: ' . str_repeat('a', 4096) . "\r\n", 2) . "\r\n"];
        yield 'missing chunk CRLF' => ["1\r\nax\r\n0\r\n\r\n"];
        yield 'incomplete trailers' => ["0\r\nx: value\r\n"];
        yield 'trailer whitespace before colon' => ["0\r\nx : value\r\n\r\n"];
        yield 'trailer obs-fold' => ["0\r\nx: value\r\n folded: value\r\n\r\n"];
    }

    public function testInvalidTrailersDoNotMarkTheBodyComplete(): void
    {
        /** @var Async\Deferred<FieldMap> $trailers */
        $trailers = new Async\Deferred();
        $handle = new ChunkedBodyHandle(new IO\Reader(new FakeStream("0\r\n")), $trailers);
        try {
            $handle->readAll();
            static::fail('Expected incomplete trailers to fail.');
        } catch (ProtocolException) {
            static::assertFalse($handle->reachedEndOfDataSource());
        }
    }

    #[DataProvider('oversizedTunnelResponses')]
    public function testConnectResponseIsBounded(string $response): void
    {
        $input = new FakeStream($response);
        $stream = static::createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback($input->read(...));
        $connector = static::createStub(TCP\ConnectorInterface::class);
        $connector->method('connect')->willReturn($stream);
        $this->expectException(ProtocolException::class);
        HttpTunnel::connect(
            $connector,
            new ProxyConfiguration(URL\parse('http://proxy.example/')),
            'example.com',
            443,
            new Async\NullCancellationToken(),
            new TLS\ClientConfiguration(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function oversizedTunnelResponses(): iterable
    {
        yield 'status line' => ['HTTP/1.1 200 ' . str_repeat('a', 8193) . "\r\n\r\n"];
        yield 'header line' => ["HTTP/1.1 200 OK\r\nx: " . str_repeat('a', 8193) . "\r\n\r\n"];
        yield 'total headers' => [
            "HTTP/1.1 200 OK\r\n" . str_repeat('x: ' . str_repeat('a', 4096) . "\r\n", 2) . "\r\n",
        ];
    }
}
