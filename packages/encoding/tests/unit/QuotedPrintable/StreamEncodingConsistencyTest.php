<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\QuotedPrintable;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

use function str_repeat;
use function str_split;

/**
 * The streaming encoders must produce exactly what {@see QuotedPrintable\encode()} produces for
 * the same input, maximum line length and line ending, regardless of how the input is chunked.
 */
final class StreamEncodingConsistencyTest extends TestCase
{
    /**
     * @param positive-int $maxLineLength
     * @param non-empty-string $lineEnding
     */
    #[DataProvider('provideCases')]
    public function testEncodingReadHandleMatchesEncode(
        string $data,
        int $maxLineLength,
        string $lineEnding,
        int $chunkSize,
    ): void {
        $handle = new QuotedPrintable\EncodingReadHandle(
            new IO\IterableReadHandle(self::chunk($data, $chunkSize)),
            $maxLineLength,
            $lineEnding,
        );

        static::assertSame(QuotedPrintable\encode($data, $maxLineLength, $lineEnding), $handle->readAll());
    }

    /**
     * @param positive-int $maxLineLength
     * @param non-empty-string $lineEnding
     */
    #[DataProvider('provideCases')]
    public function testEncodingWriteHandleMatchesEncode(
        string $data,
        int $maxLineLength,
        string $lineEnding,
        int $chunkSize,
    ): void {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\EncodingWriteHandle($inner, $maxLineLength, $lineEnding);
        foreach (self::chunk($data, $chunkSize) as $chunk) {
            $handle->writeAll($chunk);
        }

        $handle->flush();

        $inner->seek(0);
        static::assertSame(QuotedPrintable\encode($data, $maxLineLength, $lineEnding), $inner->readAll());
    }

    public function testFlushResetsTheEncoder(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\EncodingWriteHandle($inner);
        $handle->writeAll("caf\xC3\xA9\n");
        $handle->flush();
        $handle->flush();
        $handle->writeAll('=');
        $handle->flush();

        $inner->seek(0);
        static::assertSame("caf=C3=A9\r\n=3D", $inner->readAll());
    }

    /**
     * @return iterable<string, array{string, positive-int, non-empty-string, int}>
     */
    public static function provideCases(): iterable
    {
        $inputs = [
            'empty' => '',
            'single line' => 'Hello, World!',
            'trailing LF' => "Hello\n",
            'trailing CRLF' => "Hello\r\n",
            'trailing CR' => "Hello\r",
            'lone CR line break' => "a\rb",
            'only LF' => "\n",
            'only CRLFs' => "\r\n\r\n",
            'CR CRLF' => "a\r\r\nb",
            'LF CR' => "a\n\rb",
            'mixed line breaks' => "line1\r\nline2\nline3\rline4",
            'trailing whitespace' => "trailing space \nand tab\t\r\nend ",
            'long line' => str_repeat("caf\xC3\xA9 = cr\xC3\xA8me, ", 12),
            'long lines' => str_repeat(str_repeat('x', 90) . "\r\n", 3),
            'control characters' => "\x00\x01\x7F\xFF=?_",
        ];

        $parameters = [
            'RFC defaults' => [76, "\r\n"],
            'short LF lines' => [20, "\n"],
            'very short lines' => [10, "\r\n"],
        ];

        foreach ($inputs as $description => $input) {
            foreach ($parameters as $parameterDescription => [$maxLineLength, $lineEnding]) {
                foreach ([1, 2, 5, 4096] as $chunkSize) {
                    yield "{$description}, {$parameterDescription}, chunks of {$chunkSize}" => [
                        $input,
                        $maxLineLength,
                        $lineEnding,
                        $chunkSize,
                    ];
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function chunk(string $data, int $chunkSize): array
    {
        return $data === '' ? [] : str_split($data, $chunkSize);
    }
}
