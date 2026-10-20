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
 * The streaming decoders must produce exactly what {@see QuotedPrintable\decode()} produces for
 * the same input, regardless of how the input is chunked.
 */
final class StreamDecodingConsistencyTest extends TestCase
{
    #[DataProvider('provideCases')]
    public function testDecodingReadHandleMatchesDecode(string $input, int $chunkSize): void
    {
        $handle = new QuotedPrintable\DecodingReadHandle(new IO\IterableReadHandle(self::chunk($input, $chunkSize)));

        static::assertSame(QuotedPrintable\decode($input), $handle->readAll());
    }

    #[DataProvider('provideCases')]
    public function testDecodingWriteHandleMatchesDecode(string $input, int $chunkSize): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new QuotedPrintable\DecodingWriteHandle($inner);
        foreach (self::chunk($input, $chunkSize) as $chunk) {
            $handle->writeAll($chunk);
        }

        $handle->flush();

        $inner->seek(0);
        static::assertSame(QuotedPrintable\decode($input), $inner->readAll());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideCases(): iterable
    {
        $inputs = [
            'empty' => '',
            'plain' => 'Hello, World!',
            'escapes' => 'caf=C3=A9 =3D cr=C3=A8me',
            'trailing CRLF' => "Hello=20World\r\n",
            'trailing LF' => "Hello=20World\n",
            'trailing CR' => "Hello=20World\r",
            'CRLF soft break' => "Hello=\r\nWorld\r\n",
            'LF soft break' => "Hello=\nWorld\n",
            'CR soft break' => "Hello=\rWorld",
            'soft break with trailing whitespace' => "Hello= \t\r\nWorld",
            'soft break at the end' => 'Hello=',
            'soft break with whitespace at the end' => 'Hello=  ',
            'hard line breaks' => "line1\r\nline2\nline3\r\n\r\nline5",
            'empty lines' => "\r\n\r\n\n",
            'invalid escapes' => "a=XYb=4Gc=\r\n=4",
            'lowercase escapes' => 'caf=c3=a9',
            'encoded output' => QuotedPrintable\encode(str_repeat("caf\xC3\xA9 = cr\xC3\xA8me \t\n", 8), 20),
        ];

        foreach ($inputs as $description => $input) {
            foreach ([1, 2, 3, 5, 4096] as $chunkSize) {
                yield "{$description}, chunks of {$chunkSize}" => [$input, $chunkSize];
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
