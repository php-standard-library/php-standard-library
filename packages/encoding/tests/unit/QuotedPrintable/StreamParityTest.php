<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\QuotedPrintable;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

use function str_repeat;

/**
 * The streaming handles must produce the same bytes as {@see QuotedPrintable\encode()} and
 * {@see QuotedPrintable\decode()}, including the line breaks at the end of the input.
 */
final class StreamParityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideText(): iterable
    {
        yield 'empty' => [''];
        yield 'no line break' => ['abc'];
        yield 'trailing CRLF' => ["abc\r\n"];
        yield 'trailing LF' => ["abc\n"];
        yield 'trailing CR' => ["abc\r"];
        yield 'two trailing CRLF' => ["a\r\n\r\n"];
        yield 'three trailing LF' => ["a\n\n\n"];
        yield 'only CRLF' => ["\r\n"];
        yield 'only LF' => ["\n"];
        yield 'only blank lines' => ["\r\n\r\n\r\n"];
        yield 'leading blank lines' => ["\r\n\r\nabc"];
        yield 'multi line with trailing CRLF' => ["line1\r\nline2\r\n"];
        yield 'equals sign before line break' => ["x=\r\n"];
        yield 'whitespace before line breaks' => ["a \r\nb\t\r\n"];
        yield 'soft break before trailing CRLF' => [str_repeat('A', 100) . "\r\n"];
        yield 'non-ASCII with trailing CRLF' => ["caf\u{e9}\r\ncr\u{e8}me\r\n"];
    }

    #[DataProvider('provideText')]
    public function testEncodingReadHandleMatchesEncode(string $text): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle($text));

        static::assertSame(QuotedPrintable\encode($text), $handle->readAll());
    }

    #[DataProvider('provideText')]
    public function testEncodingReadHandleMatchesEncodeWhenReadByteByByte(string $text): void
    {
        $handle = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle($text));

        static::assertSame(QuotedPrintable\encode($text), self::readInSingleBytes($handle));
    }

    #[DataProvider('provideText')]
    public function testDecodingReadHandleMatchesDecode(string $text): void
    {
        $encoded = QuotedPrintable\encode($text);
        $handle = new QuotedPrintable\DecodingReadHandle(new IO\MemoryHandle($encoded));

        static::assertSame(QuotedPrintable\decode($encoded), $handle->readAll());
    }

    #[DataProvider('provideText')]
    public function testDecodingReadHandleMatchesDecodeWhenReadByteByByte(string $text): void
    {
        $encoded = QuotedPrintable\encode($text);
        $handle = new QuotedPrintable\DecodingReadHandle(new IO\MemoryHandle($encoded));

        static::assertSame(QuotedPrintable\decode($encoded), self::readInSingleBytes($handle));
    }

    #[DataProvider('provideText')]
    public function testReadHandlesRoundTrip(string $text): void
    {
        $encoded = new QuotedPrintable\EncodingReadHandle(new IO\MemoryHandle($text))->readAll();
        $decoded = new QuotedPrintable\DecodingReadHandle(new IO\MemoryHandle($encoded))->readAll();

        static::assertSame(QuotedPrintable\decode(QuotedPrintable\encode($text)), $decoded);
    }

    public function testDecodingReadHandleKeepsHardBreakAfterTrailingSoftBreak(): void
    {
        $handle = new QuotedPrintable\DecodingReadHandle(new IO\MemoryHandle("abc\r\n=\r\n"));

        static::assertSame("abc\r\n", $handle->readAll());
    }

    private static function readInSingleBytes(IO\ReadHandleInterface $handle): string
    {
        $data = '';
        do {
            $data .= $handle->read(1);
        } while (!$handle->reachedEndOfDataSource());

        return $data;
    }
}
