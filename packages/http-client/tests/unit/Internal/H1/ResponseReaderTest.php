<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Internal\H1\ResponseReader;
use Psl\HTTP\Client\Tests\Fixture\H1\FakeStream;
use Psl\HTTP\Client\Tests\Fixture\H1\SlowDripStream;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Response;
use Psl\IO;

use function implode;
use function str_repeat;
use function strlen;

final class ResponseReaderTest extends TestCase
{
    private static function reader(string $raw): IO\Reader
    {
        return new IO\Reader(new FakeStream($raw));
    }

    private static function timeout(): CancellationTokenInterface
    {
        return new TimeoutCancellationToken(Duration::seconds(5));
    }

    public function testBasicOkResponse(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-length: 5\r\n\r\nhello";
        [$response, $keepAlive] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame(200, $response->status);
        static::assertSame(ProtocolVersion::V11, $response->protocolVersion);
        static::assertTrue($keepAlive);
        $body = $response->body;
        static::assertNotNull($body);
        static::assertSame('hello', $body->readAll());
    }

    public function testHttp10Response(): void
    {
        $raw = "HTTP/1.0 200 OK\r\ncontent-length: 3\r\n\r\nfoo";
        [$response, $keepAlive] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame(200, $response->status);
        static::assertSame(ProtocolVersion::V10, $response->protocolVersion);
        static::assertFalse($keepAlive);
    }

    public function testHttp10WithKeepAlive(): void
    {
        $raw = "HTTP/1.0 200 OK\r\nconnection: keep-alive\r\ncontent-length: 0\r\n\r\n";
        [$response, $keepAlive] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame(200, $response->status);
        static::assertTrue($keepAlive);
    }

    public function testHttp11WithConnectionClose(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nconnection: close\r\ncontent-length: 0\r\n\r\n";
        [$response, $keepAlive] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame(200, $response->status);
        static::assertFalse($keepAlive);
    }

    public function test204HasNoBody(): void
    {
        $raw = "HTTP/1.1 204 No Content\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        self::assertSame(204, $response->status);
        self::assertNull($response->body);
    }

    public function test304HasNoBody(): void
    {
        $raw = "HTTP/1.1 304 Not Modified\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        self::assertSame(304, $response->status);
        self::assertNull($response->body);
    }

    public function testHeadRequestHasNoBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-length: 1000\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), true);

        static::assertSame(200, $response->status);
        static::assertNull($response->body);
    }

    public function testChunkedBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ntransfer-encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        $body = $response->body;
        static::assertNotNull($body);
        static::assertSame('hello', $body->readAll());
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('chunkedTransferEncodings')]
    public function testChunkedTransferEncodingOverridesContentLength(array $values): void
    {
        $raw =
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: "
            . implode("\r\nTransfer-Encoding: ", $values)
            . "\r\nContent-Length: 0\r\n\r\n5\r\nhello\r\n0\r\nx-check: pass\r\n\r\n";
        [$response, $keepAlive] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertTrue($keepAlive);
        $body = $response->body;
        static::assertNotNull($body);
        static::assertSame('hello', $body->readAll());
        $trailers = $response->trailers;
        static::assertNotNull($trailers);
        static::assertSame('pass', $trailers->await()->get('x-check'));
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('invalidTransferEncodings')]
    public function testInvalidOrUnsupportedTransferEncodingThrows(array $values, null|int $contentLength): void
    {
        $raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: " . implode("\r\nTransfer-Encoding: ", $values) . "\r\n";
        if ($contentLength !== null) {
            $raw .= "Content-Length: {$contentLength}\r\n";
        }

        $raw .= "\r\n5\r\nhello\r\n0\r\n\r\n";

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid or unsupported transfer-encoding.');

        ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);
    }

    #[DataProvider('bodylessResponsesWithTransferEncoding')]
    public function testTransferEncodingDoesNotAddBody(int $status, bool $isHead): void
    {
        $raw = "HTTP/1.1 {$status} Response\r\nTransfer-Encoding: gzip, chunked\r\n\r\n";
        [$response, $keepAlive] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), $isHead);

        static::assertNull($response->body);
        static::assertNull($response->trailers);
        static::assertTrue($keepAlive);
    }

    public function testUntilCloseBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\n\r\nsome body data";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        $body = $response->body;
        static::assertNotNull($body);
        static::assertSame('some body data', $body->readAll());
    }

    public function testEmptyResponseThrows(): void
    {
        $this->expectException(ProtocolException::class);

        ResponseReader::read(self::reader(''), 8192, self::timeout(), false);
    }

    public function testConflictingContentLengthFieldsThrow(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-length: 5\r\ncontent-length: 500\r\n\r\nhello";

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Conflicting content-length');

        ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);
    }

    public function testIdenticalDuplicateContentLengthFieldsCollapse(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-length: 5\r\ncontent-length: 5\r\n\r\nhello";
        [$response] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        $body = $response->body;
        static::assertNotNull($body);
        static::assertSame('hello', $body->readAll());
    }

    public function testMalformedStatusLineNoSpace(): void
    {
        $this->expectException(ProtocolException::class);

        ResponseReader::read(self::reader("HTTP/1.1\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testUnsupportedHttpVersion(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unsupported HTTP version');

        ResponseReader::read(self::reader("HTTP/2.0 200 OK\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testNonNumericStatusCode(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid status code');

        ResponseReader::read(self::reader("HTTP/1.1 abc OK\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testStatusCodeTooShort(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid status code');

        ResponseReader::read(self::reader("HTTP/1.1 20 OK\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testStatusCodeTooLong(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid status code');

        ResponseReader::read(self::reader("HTTP/1.1 2000 OK\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testInvalidHeaderLineNoColon(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid header line');

        ResponseReader::read(self::reader("HTTP/1.1 200 OK\r\nbroken-header\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testEmptyHeaderName(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Empty header name');

        ResponseReader::read(self::reader("HTTP/1.1 200 OK\r\n: value\r\n\r\n"), 8192, self::timeout(), false);
    }

    public function testHeadersExceedMaxSize(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('headers exceed maximum size');

        $longHeader = 'x-big: ' . str_repeat('A', 200) . "\r\n";
        $raw = "HTTP/1.1 200 OK\r\n" . $longHeader . "\r\n";

        ResponseReader::read(self::reader($raw), 64, self::timeout(), false);
    }

    public function testStatusLineExceedsMaxSize(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Status line exceeds maximum size');

        $raw = 'HTTP/1.1 200 ' . str_repeat('X', 200) . "\r\n\r\n";

        ResponseReader::read(self::reader($raw), 32, self::timeout(), false);
    }

    public function testStatusCodeWithoutReasonPhrase(): void
    {
        $raw = "HTTP/1.1 200\r\ncontent-length: 0\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame(200, $response->status);
    }

    public function testMultipleHeadersWithSameName(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nset-cookie: a=1\r\nset-cookie: b=2\r\ncontent-length: 0\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        $cookies = $response->headers->getAll('set-cookie');
        static::assertCount(2, $cookies);
        static::assertSame('a=1', $cookies[0]);
        static::assertSame('b=2', $cookies[1]);
    }

    public function testReadWithInformational(): void
    {
        $raw = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 4\r\n\r\ndone";
        [$informational, $response, $_] = ResponseReader::readWithInformational(
            self::reader($raw),
            new ClientConfiguration(),
            self::timeout(),
            false,
        );

        static::assertCount(1, $informational);
        static::assertSame(100, $informational[0]->status);
        static::assertSame(200, $response->status);
        $body = $response->body;
        static::assertNotNull($body);
        static::assertSame('done', $body->readAll());
    }

    public function testReadWithMultipleInformational(): void
    {
        $raw = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 102 Processing\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 2\r\n\r\nok";
        [$informational, $response, $_] = ResponseReader::readWithInformational(
            self::reader($raw),
            new ClientConfiguration(),
            self::timeout(),
            false,
        );

        static::assertCount(2, $informational);
        static::assertSame(100, $informational[0]->status);
        static::assertSame(102, $informational[1]->status);
        static::assertSame(200, $response->status);
    }

    public function testZeroContentLength(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame(200, $response->status);
        static::assertNull($response->body);
    }

    public function testNullBytesInHeaderValue(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nx-test: foo\x00bar\r\ncontent-length: 0\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame("foo\x00bar", $response->headers->get('x-test'));
    }

    public function testHeaderValueWithLeadingWhitespace(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nx-test:   spaced  \r\ncontent-length: 0\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame('spaced', $response->headers->get('x-test'));
    }

    #[DataProvider('informationalStatusCodes')]
    public function testInformationalStatusCodesHaveNoBody(int $status): void
    {
        $raw = "HTTP/1.1 {$status} Info\r\n\r\n";
        [$response, $_] = ResponseReader::read(self::reader($raw), 8192, self::timeout(), false);

        static::assertSame($status, $response->status);
        static::assertNull($response->body);
    }

    public function testHeaderTimeoutIsIncrementalNotPerRead(): void
    {
        $headers = '';
        for ($i = 0; $i < 20; $i++) {
            $headers .= "x-header-{$i}: value{$i}\r\n";
        }

        $raw = "HTTP/1.1 200 OK\r\n{$headers}\r\n";

        $stream = new SlowDripStream($raw, Duration::milliseconds(30));
        $reader = new IO\Reader($stream);

        $start = Timestamp::monotonic();

        try {
            ResponseReader::read($reader, 8192, new TimeoutCancellationToken(Duration::milliseconds(300)), false);
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertLessThan(1000, $elapsed);
    }

    public function testInformationalResponsesShareSingleTimeoutBudget(): void
    {
        $raw = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n";

        $stream = new SlowDripStream($raw, Duration::milliseconds(20));
        $reader = new IO\Reader($stream);

        $start = Timestamp::monotonic();

        try {
            ResponseReader::readWithInformational(
                $reader,
                new ClientConfiguration(),
                new TimeoutCancellationToken(Duration::milliseconds(400)),
                false,
            );
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertLessThan(1500, $elapsed);
    }

    public function testHeaderBytesReachMaxBeforeNextRead(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('headers exceed maximum size');

        $headerLine = 'x-a: ' . str_repeat('A', 20);
        $headerLineWithCrlf = $headerLine . "\r\n";
        $raw = "HTTP/1.1 200 OK\r\n" . $headerLineWithCrlf . "x-b: value\r\n\r\n";

        $maxHeaderSize = strlen($headerLine) + 2;

        /** @var positive-int $maxHeaderSize */
        ResponseReader::read(self::reader($raw), $maxHeaderSize, self::timeout(), false);
    }

    public function testMaxResponseBodySizeWrapsBodyInLimitedHandle(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ncontent-length: 20\r\n\r\n" . str_repeat('X', 20);
        [$response, $_] = ResponseReader::read(
            self::reader($raw),
            8192,
            self::timeout(),
            false,
            maxResponseBodySize: 10,
        );

        $body = $response->body;
        static::assertNotNull($body);

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured limit');
        $body->readAll();
    }

    public function testMaxResponseBodySizeWrapsChunkedBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\ntransfer-encoding: chunked\r\n\r\n14\r\n" . str_repeat('Y', 20) . "\r\n0\r\n\r\n";
        [$response, $_] = ResponseReader::read(
            self::reader($raw),
            8192,
            self::timeout(),
            false,
            maxResponseBodySize: 5,
        );

        $body = $response->body;
        static::assertNotNull($body);

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured limit');
        $body->readAll();
    }

    public function testOnInformationalResponseCallbackInvoked(): void
    {
        $raw = "HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 2\r\n\r\nok";
        $reader = new IO\Reader(new IO\MemoryHandle($raw));

        $received = [];
        $config = new ClientConfiguration(onInformationalResponse: static function (Response $r) use (
            &$received,
        ): void {
            $received[] = $r->status;
        });

        [$informational, $response] = ResponseReader::readWithInformational(
            $reader,
            $config,
            new NullCancellationToken(),
            false,
        );

        static::assertSame([103], $received);
        static::assertCount(1, $informational);
        static::assertSame(103, $informational[0]->status);
        static::assertSame(200, $response->status);
    }

    public function testOnInformationalResponseCallbackMultiple(): void
    {
        $raw = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 103 Early Hints\r\nLink: </a>\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n";
        $reader = new IO\Reader(new IO\MemoryHandle($raw));

        $received = [];
        $config = new ClientConfiguration(onInformationalResponse: static function (Response $r) use (
            &$received,
        ): void {
            $received[] = $r->status;
        });

        [$informational, $response] = ResponseReader::readWithInformational(
            $reader,
            $config,
            new NullCancellationToken(),
            false,
        );

        static::assertSame([100, 103], $received);
        static::assertCount(2, $informational);
        static::assertSame(200, $response->status);
    }

    public function testOnInformationalResponseCallbackNullDoesNotThrow(): void
    {
        $raw = "HTTP/1.1 103 Early Hints\r\nLink: </a>\r\n\r\nHTTP/1.1 200 OK\r\ncontent-length: 0\r\n\r\n";
        $reader = new IO\Reader(new IO\MemoryHandle($raw));

        $config = new ClientConfiguration();

        [$informational, $response] = ResponseReader::readWithInformational(
            $reader,
            $config,
            new NullCancellationToken(),
            false,
        );

        static::assertCount(1, $informational);
        static::assertSame(200, $response->status);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function chunkedTransferEncodings(): iterable
    {
        yield 'chunked' => [['chunked']];
        yield 'case and whitespace' => [[" \tChUnKeD\t "]];
        yield 'empty list elements' => [[" , \tchunked, , \t"]];
        yield 'empty first field' => [['', 'chunked']];
        yield 'empty last field' => [['chunked', '']];
    }

    /**
     * @return iterable<string, array{list<string>, null|int}>
     */
    public static function invalidTransferEncodings(): iterable
    {
        $cases = [
            'identity then chunked fields' => ['identity', 'chunked'],
            'chunked then identity fields' => ['chunked', 'identity'],
            'repeated chunked fields' => ['chunked', 'chunked'],
            'identity then chunked list' => ['identity, chunked'],
            'chunked then identity list' => ['chunked, identity'],
            'repeated chunked list' => ['chunked, chunked'],
            'gzip then chunked fields' => ['gzip', 'chunked'],
            'gzip then chunked list' => ['gzip, chunked'],
            'identity' => ['identity'],
            'gzip' => ['gzip'],
            'empty field' => [''],
            'empty list' => [', ,'],
            'chunked parameters' => ['chunked;foo=bar'],
            'partial token' => ['xchunked'],
            'invalid whitespace' => ["\vchunked\v"],
        ];

        foreach ($cases as $name => $values) {
            foreach ([null, 0, 5] as $contentLength) {
                yield $name . ' / content-length ' . ($contentLength ?? 'absent') => [$values, $contentLength];
            }
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function bodylessResponsesWithTransferEncoding(): iterable
    {
        yield 'HEAD' => [200, true];
        yield '304' => [304, false];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function informationalStatusCodes(): iterable
    {
        yield '100' => [100];
        yield '101' => [101];
        yield '102' => [102];
        yield '103' => [103];
    }
}
