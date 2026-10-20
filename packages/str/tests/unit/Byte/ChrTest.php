<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit\Byte;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str\Byte;

use function restore_error_handler;
use function set_error_handler;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class ChrTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testChr(string $expected, int $value): void
    {
        static::assertSame($expected, Byte\chr($value));
    }

    public static function provideData(): array
    {
        return [
            ['E', 1605],
            ['0', 48],
            ['&', 38],
            ['\'', 1575],
            ['A', 65],
            ["\x00", 0],
            ["\xFF", 255],
            ["\x00", 256],
            ["\xFF", -1],
            ["\x80", -128],
            ["\x01", -255],
            ["\x00", -256],
            ["\xFF", -257],
            ["\xBB", -1605],
            ["\x00", PHP_INT_MIN],
            ["\xFF", PHP_INT_MAX],
        ];
    }

    /**
     * PHP 8.5 deprecated passing values outside 0..255 to chr().
     */
    #[DataProvider('provideData')]
    public function testChrDoesNotTriggerDeprecation(string $expected, int $value): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            static::assertSame($expected, Byte\chr($value));
        } finally {
            restore_error_handler();
        }
    }
}
