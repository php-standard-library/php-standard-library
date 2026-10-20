<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\Base64;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\IO;

use function str_repeat;
use function str_split;
use function substr;

/**
 * The streaming encoders must produce exactly what {@see Base64\encode()} produces for the
 * same input, variant and padding, regardless of how the input is chunked.
 */
final class StreamEncodingConsistencyTest extends TestCase
{
    #[DataProvider('provideCases')]
    public function testEncodingReadHandleMatchesEncode(
        string $data,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): void {
        $handle = new Base64\EncodingReadHandle(
            new IO\IterableReadHandle(self::chunk($data, $chunkSize)),
            $variant,
            $padding,
        );

        static::assertSame(Base64\encode($data, $variant, $padding), $handle->readAll());
    }

    #[DataProvider('provideCases')]
    public function testEncodingWriteHandleMatchesEncode(
        string $data,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): void {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner, $variant, $padding);
        foreach (self::chunk($data, $chunkSize) as $chunk) {
            $handle->writeAll($chunk);
        }

        $handle->flush();

        $inner->seek(0);
        static::assertSame(Base64\encode($data, $variant, $padding), $inner->readAll());
    }

    public function testFlushResetsTheEncoder(): void
    {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\EncodingWriteHandle($inner, Base64\Variant::Mime);
        $handle->writeAll(str_repeat('a', 100));
        $handle->flush();
        $handle->flush();
        $handle->writeAll('b');
        $handle->flush();

        $inner->seek(0);
        static::assertSame(
            Base64\encode(str_repeat('a', 100), Base64\Variant::Mime) . Base64\encode('b', Base64\Variant::Mime),
            $inner->readAll(),
        );
    }

    /**
     * @return iterable<string, array{string, Base64\Variant, bool, int}>
     */
    public static function provideCases(): iterable
    {
        $source = str_repeat("\x00\xFF\xFB\xEF\xBE\x3E\x3F Hello, World!", 20);

        foreach (Base64\Variant::cases() as $variant) {
            foreach ([true, false] as $padding) {
                foreach ([0, 1, 2, 3, 4, 56, 57, 58, 115, 200] as $length) {
                    foreach ([1, 2, 7, 57, 4096] as $chunkSize) {
                        $name =
                            $variant->name
                            . ($padding ? ', padded' : ', unpadded')
                            . ", {$length} bytes in chunks of {$chunkSize}";

                        yield $name => [substr($source, 0, $length), $variant, $padding, $chunkSize];
                    }
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
