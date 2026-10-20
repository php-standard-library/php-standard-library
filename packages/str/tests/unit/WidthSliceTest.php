<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

final class WidthSliceTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testWidthSlice(string $expected, string $string, int $offset, int $width): void
    {
        static::assertSame($expected, Str\width_slice($string, $offset, $width));
    }

    public static function provideData(): array
    {
        return [
            // Basic ASCII
            ['',              '',               0, 0],
            ['Hello',         'Hello, World!',  0, 5],
            ['Hello, World!', 'Hello, World!',  0, 100],
            ['World!',        'Hello, World!',  7, 6],

            // CJK characters (each 2 columns wide)
            ['你',            '你好世界',       0, 2],
            ['你好',          '你好世界',       0, 4],
            ['你好',          '你好世界',       0, 5], // 5 columns: fits 2 CJK chars (4 cols), next would be 6
            ['你好世',        '你好世界',       0, 6],
            ['你好世界',      '你好世界',       0, 8],
            ['你好世界',      '你好世界',       0, 100],

            // Mixed ASCII and CJK
            ['Hello世',       'Hello世界Test',  0, 7],
            ['Hello世界',     'Hello世界Test',  0, 9],
            ['Hello世界T',    'Hello世界Test',  0, 10],
            ['Hello世界Test', 'Hello世界Test',  0, 100],

            // Japanese (each 2 columns wide)
            ['こんに',        'こんにちは世界', 0, 6],
            ['こんにちは',    'こんにちは世界', 0, 10],

            // Arabic (1 column each)
            ['مرحبا',         'مرحبا سيف',      0, 5],

            // Offset (codepoint-based)
            ['世界',          '你好世界',       2, 4],
            ['Test',          'Hello世界Test',  7, 100],

            // Zero width
            ['',              'Hello',          0, 0],
            ['',              '你好',           0, 0],

            // Width 1 with CJK (can't fit a 2-col char)
            ['',              '你好',           0, 1],

            // Emoji (typically 2 columns)
            ['🥇',            '🥇🥈🥉',         0, 2],
        ];
    }

    public function testNegativeWidthThrows(): void
    {
        $this->expectException(Str\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('$width must be a non-negative integer.');

        Str\width_slice('Hello, World!', 0, -2);
    }
}
