<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Internal\H1\H1Connection;
use Psl\HTTP\Client\Internal\H1\Transport;
use Psl\HTTP\Client\Tests\Fixture\H1\FakeStream;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\URL;

use function str_contains;
use function substr_count;

final class ExpectContinueHardeningTest extends TestCase
{
    #[DataProvider('continueResponses')]
    public function testContinueSendsBodyOnceAndReadsAllInterimFields(string $interim): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            headers: FieldMap::from([['expect', '100-continue']]),
            body: new IO\MemoryHandle('payload'),
        );
        $stream = new FakeStream($interim . "HTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        [$transaction, $keepAlive] = Transport::exchange($connection, $request, $url, new ClientConfiguration());
        static::assertSame(200, $transaction->response->status);
        static::assertTrue($keepAlive);
        static::assertSame(1, substr_count($stream->written, 'payload'));
        static::assertStringEndsWith("7\r\npayload\r\n0\r\n\r\n", $stream->written);
        static::assertNotEmpty($transaction->informational);
    }

    /** @return iterable<string, array{string}> */
    public static function continueResponses(): iterable
    {
        yield 'HTTP/1.0 continue' => ["HTTP/1.0 100 Continue\r\n\r\n"];
        yield 'continue with headers' => ["HTTP/1.1 100 Continue\r\nx-one: 1\r\nx-two: 2\r\n\r\n"];
        yield 'early hints before continue' => [
            "HTTP/1.1 103 Early Hints\r\nlink: </style.css>\r\n\r\nHTTP/1.1 100 Continue\r\n\r\n",
        ];
        yield 'repeated continue' => ["HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 100 Continue\r\n\r\n"];
    }

    public function testEmptyChunkedBodyIsTerminatedWithExpect(): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(method: 'POST', url: $url, headers: FieldMap::from([
            ['expect',            '100-continue'],
            ['transfer-encoding', 'chunked'],
        ]));
        $stream = new FakeStream("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        Transport::exchange($connection, $request, $url, new ClientConfiguration());
        static::assertStringEndsWith("\r\n\r\n0\r\n\r\n", $stream->written);
    }

    public function testFinalResponseBeforeBodyDisablesReuse(): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            headers: FieldMap::from([
                ['expect',         '100-continue'],
                ['content-length', '7'],
            ]),
            body: new IO\MemoryHandle('payload'),
        );
        $stream = new FakeStream("HTTP/1.1 417 Expectation Failed\r\ncontent-length: 0\r\n\r\n");
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        [$transaction, $keepAlive] = Transport::exchange($connection, $request, $url, new ClientConfiguration());
        static::assertSame(417, $transaction->response->status);
        static::assertFalse($keepAlive);
        static::assertStringNotContainsString('payload', $stream->written);
    }

    public function testConfiguredExpectContinueTimeoutIsHonored(): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            headers: FieldMap::from([['expect', '100-continue']]),
            body: new IO\MemoryHandle('payload'),
        );
        $written = '';
        $stream = static::createStub(Network\StreamInterface::class);
        $stream
            ->method('writeAll')
            ->willReturnCallback(static function (string $bytes) use (&$written): void {
                $written .= $bytes;
            });
        $stream
            ->method('read')
            ->willReturnCallback(static function (null|int $_, Async\CancellationTokenInterface $cancellation) use (
                &$written,
            ): string {
                // Never send 100 Continue; wait for the body, which the short
                // configured timeout must release.
                while (!str_contains($written, 'payload')) {
                    Async\sleep(Duration::milliseconds(5), $cancellation);
                }

                return "HTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n";
            });
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        [$transaction] = Transport::exchange(
            $connection,
            $request,
            $url,
            new ClientConfiguration(expectContinueTimeout: Duration::milliseconds(10)),
            new Async\TimeoutCancellationToken(Duration::seconds(2)),
        );

        static::assertSame(200, $transaction->response->status);
        static::assertSame(1, substr_count($written, 'payload'));
    }

    #[DataProvider('partialResponses')]
    public function testTimeoutSendsBodyAndPreservesPartialResponse(string $prefix, string $suffix): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            headers: FieldMap::from([['expect', '100-continue']]),
            body: new IO\MemoryHandle('payload'),
        );
        $written = '';
        $stream = static::createStub(Network\StreamInterface::class);
        $stream
            ->method('writeAll')
            ->willReturnCallback(static function (string $bytes) use (&$written): void {
                $written .= $bytes;
            });
        $stream
            ->method('read')
            ->willReturnCallback(static function (null|int $_, Async\CancellationTokenInterface $cancellation) use (
                &$written,
                &$prefix,
                $suffix,
            ): string {
                if ($prefix !== '') {
                    $result = $prefix;
                    $prefix = '';
                    return $result;
                }

                while (!str_contains($written, 'payload')) {
                    Async\sleep(Duration::milliseconds(10), $cancellation);
                }

                return $suffix;
            });
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        [$transaction] = Transport::exchange(
            $connection,
            $request,
            $url,
            new ClientConfiguration(),
            new Async\TimeoutCancellationToken(Duration::seconds(2)),
        );
        static::assertSame(200, $transaction->response->status);
        static::assertSame(1, substr_count($written, 'payload'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function partialResponses(): iterable
    {
        yield 'silent server' => ['', "HTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n"];
        yield 'split status line' => ['HTTP/1.1 100 Cont', "inue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n"];
        yield 'split interim header' => [
            "HTTP/1.1 100 Continue\r\nx-test: par",
            "tial\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n",
        ];
    }

    public function testCallerCancellationDoesNotSendBody(): void
    {
        $url = URL\parse('http://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            headers: FieldMap::from([['expect', '100-continue']]),
            body: new IO\MemoryHandle('payload'),
        );
        $written = '';
        $stream = static::createStub(Network\StreamInterface::class);
        $stream
            ->method('writeAll')
            ->willReturnCallback(static function (string $bytes) use (&$written): void {
                $written .= $bytes;
            });
        $stream
            ->method('read')
            ->willReturnCallback(static function (null|int $_, Async\CancellationTokenInterface $cancellation): string {
                Async\sleep(Duration::seconds(5), $cancellation);
                return '';
            });
        $connection = new H1Connection($stream, new ConnectionMetadata(Network\Address::tcp(), Network\Address::tcp()));
        try {
            Transport::exchange(
                $connection,
                $request,
                $url,
                new ClientConfiguration(),
                new Async\TimeoutCancellationToken(Duration::milliseconds(20)),
            );
            static::fail('Expected caller cancellation.');
        } catch (Async\Exception\CancelledException) {
            static::assertStringNotContainsString('payload', $written);
        }
    }
}
