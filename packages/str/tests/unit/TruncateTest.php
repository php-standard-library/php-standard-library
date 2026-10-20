<?php

declare(strict_types=1);

namespace Psl\Str\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;

final class TruncateTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testTruncate(
        string $expected,
        string $str,
        int $offset,
        int $width,
        null|string $trimMarker = null,
    ): void {
        static::assertSame($expected, Str\truncate($str, $offset, $width, $trimMarker));
    }

    public static function provideData(): array
    {
        return [
            ['Hello',    'Hello, World!', 0, 5],
            ['He...',    'Hello, World!', 0, 5, '...'],
            ['Hello...', 'Hello, World!', 0, 8, '...'],
            ['héllö...', 'héllö, wôrld!', 0, 8, '...'],
            ['wôrld!',   'héllö, wôrld!', 7, 8, '...'],
            ['مرحبا...', 'مرحبا بكم',     0, 8, '...'],
            ['سيف',      'مرحبا سيف',     6, 8, '...'],
            ['',         'Hello, World!', 0, 0],
        ];
    }

    public function testNegativeWidthThrows(): void
    {
        $this->expectException(Str\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('$width must be a non-negative integer.');

        Str\truncate('Hello, World!', 0, -2);
    }
}
