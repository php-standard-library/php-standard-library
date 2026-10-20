<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Internal\H1\ChunkedBodyHandle;
use Psl\HTTP\Client\Tests\Fixture\H1\FakeStream;
use Psl\HTTP\Client\Tests\Fixture\H1\SlowDripStream;
use Psl\HTTP\Message\FieldMap;
use Psl\IO;

use function dechex;
use function str_repeat;

final class ChunkedBodyHandleTest extends TestCase
{
    /**
     * @return array{ChunkedBodyHandle, Async\Deferred<FieldMap>}
     */
    private static function handle(string $raw): array
    {
        /** @var Async\Deferred<FieldMap> $deferred */
        $deferred = new Async\Deferred();

        return [new ChunkedBodyHandle(new IO\Reader(new FakeStream($raw)), $deferred), $deferred];
    }

    public function testSingleChunk(): void
    {
        [$handle] = self::handle("5\r\nhello\r\n0\r\n\r\n");

        static::assertSame('hello', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testMultipleChunks(): void
    {
        [$handle] = self::handle("5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n");

        static::assertSame('hello world', $handle->readAll());
    }

    public function testChunkWithExtension(): void
    {
        [$handle] = self::handle("5;ext=val\r\nhello\r\n0\r\n\r\n");

        static::assertSame('hello', $handle->readAll());
    }

    public function testChunkSizeWithBadWhitespaceBeforeExtension(): void
    {
        [$handle] = self::handle("5 \t;ext=val\r\nhello\r\n0\r\n\r\n");

        static::assertSame('hello', $handle->readAll());
    }

    public function testChunkSizeSurroundedBySpacesAndTabsIsTolerated(): void
    {
        [$handle] = self::handle(" \t5 \t\r\nhello\r\n0\r\n\r\n");

        static::assertSame('hello', $handle->readAll());
    }

    #[DataProvider('provideNonBwsWhitespace')]
    public function testChunkSizeWithNonBwsWhitespaceThrows(string $whitespace): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid chunk size');

        [$handle] = self::handle("5{$whitespace};ext=val\r\nhello\r\n0\r\n\r\n");
        $handle->readAll();
    }

    #[DataProvider('provideNonBwsWhitespace')]
    public function testChunkSizeWithTrailingNonBwsWhitespaceThrows(string $whitespace): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid chunk size');

        [$handle] = self::handle("5{$whitespace}\r\nhello\r\n0\r\n\r\n");
        $handle->readAll();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonBwsWhitespace(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
        yield 'bare LF' => ["\n"];
        yield 'bare CR' => ["\r"];
    }

    public function testEmptyChunkedBody(): void
    {
        [$handle] = self::handle("0\r\n\r\n");

        static::assertSame('', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testHexUppercaseChunkSize(): void
    {
        [$handle] = self::handle("A\r\n0123456789\r\n0\r\n\r\n");

        static::assertSame('0123456789', $handle->readAll());
    }

    public function testHexLowercaseChunkSize(): void
    {
        [$handle] = self::handle("a\r\n0123456789\r\n0\r\n\r\n");

        static::assertSame('0123456789', $handle->readAll());
    }

    public function testInvalidChunkSizeThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid chunk size');

        [$handle] = self::handle("xyz\r\nhello\r\n0\r\n\r\n");
        $handle->readAll();
    }

    public function testEmptyChunkSizeLineThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid chunk size');

        [$handle] = self::handle("\r\nhello\r\n0\r\n\r\n");
        $handle->readAll();
    }

    public function testNegativeHexThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid chunk size');

        [$handle] = self::handle("-5\r\nhello\r\n0\r\n\r\n");
        $handle->readAll();
    }

    public function testTryReadReturnsEmptyWhenNoData(): void
    {
        [$handle] = self::handle("5\r\nhello\r\n0\r\n\r\n");

        static::assertSame('', $handle->tryRead());
    }

    public function testReadWithMaxBytes(): void
    {
        [$handle] = self::handle("a\r\n0123456789\r\n0\r\n\r\n");

        static::assertSame('01234', $handle->read(5));
        static::assertSame('56789', $handle->read(5));
        static::assertSame('', $handle->read());
        static::assertTrue($handle->reachedEndOfDataSource());
    }

    public function testLargeChunkSize(): void
    {
        $body = str_repeat('X', 1024);
        $hex = dechex(1024);
        [$handle] = self::handle("{$hex}\r\n{$body}\r\n0\r\n\r\n");

        static::assertSame($body, $handle->readAll());
    }

    public function testTimeoutIsIncrementalWithinSingleRead(): void
    {
        $extension = str_repeat('x', 40);
        $raw = "5;{$extension}\r\nhello\r\n0\r\n\r\n";

        $stream = new SlowDripStream($raw, Duration::milliseconds(15));
        /** @var Async\Deferred<FieldMap> $deferred */
        $deferred = new Async\Deferred();
        $handle = new ChunkedBodyHandle(new IO\Reader($stream), $deferred);

        $start = Timestamp::monotonic();

        try {
            $handle->read(null, new Async\TimeoutCancellationToken(Duration::milliseconds(300)));
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertLessThan(1500, $elapsed);
    }

    public function testTrailersResolvedAfterBodyConsumed(): void
    {
        [$handle, $deferred] = self::handle("5\r\nhello\r\n0\r\nx-checksum: abc123\r\nx-count: 42\r\n\r\n");

        static::assertSame('hello', $handle->readAll());

        $trailers = $deferred->getAwaitable()->await();
        static::assertSame('abc123', $trailers->get('x-checksum'));
        static::assertSame('42', $trailers->get('x-count'));
    }

    public function testEmptyTrailers(): void
    {
        [$handle, $deferred] = self::handle("5\r\nhello\r\n0\r\n\r\n");

        static::assertSame('hello', $handle->readAll());

        $trailers = $deferred->getAwaitable()->await();
        static::assertTrue($trailers->isEmpty());
    }

    public function testSingleTrailer(): void
    {
        [$handle, $deferred] = self::handle("3\r\nfoo\r\n0\r\nx-digest: sha256=abc\r\n\r\n");

        $handle->readAll();

        $trailers = $deferred->getAwaitable()->await();
        static::assertSame('sha256=abc', $trailers->get('x-digest'));
    }

    public function testTrailersWithWhitespace(): void
    {
        [$handle, $deferred] = self::handle("3\r\nfoo\r\n0\r\nx-value:   spaced  \r\n\r\n");

        $handle->readAll();

        $trailers = $deferred->getAwaitable()->await();
        static::assertSame('spaced', $trailers->get('x-value'));
    }

    public function testMalformedTrailerLineSkipped(): void
    {
        [$handle, $deferred] = self::handle("3\r\nfoo\r\n0\r\nmalformed-no-colon\r\nx-valid: ok\r\n\r\n");

        $handle->readAll();

        $trailers = $deferred->getAwaitable()->await();
        static::assertNull($trailers->get('malformed-no-colon'));
        static::assertSame('ok', $trailers->get('x-valid'));
    }

    public function testTrailersResolvedForEmptyBody(): void
    {
        [$handle, $deferred] = self::handle("0\r\nx-trailer: present\r\n\r\n");

        static::assertSame('', $handle->readAll());

        $trailers = $deferred->getAwaitable()->await();
        static::assertSame('present', $trailers->get('x-trailer'));
    }

    public function testTrailersDefaultOnUnexpectedEof(): void
    {
        [$handle] = self::handle('');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Connection closed before chunk size');

        $handle->readAll();
    }

    public function testTryReadReturnsBufferedData(): void
    {
        [$handle] = self::handle("a\r\n0123456789\r\n0\r\n\r\n");

        static::assertSame('01234', $handle->read(5));

        static::assertSame('56789', $handle->tryRead());
    }

    public function testReadReturnsEmptyWhenComplete(): void
    {
        [$handle] = self::handle("5\r\nhello\r\n0\r\n\r\n");

        static::assertSame('hello', $handle->readAll());
        static::assertTrue($handle->reachedEndOfDataSource());

        static::assertSame('', $handle->read());
    }
}
