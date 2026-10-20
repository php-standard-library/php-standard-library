<?php

declare(strict_types=1);

namespace Psl\Math\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Math;
use Psl\Str;

final class FromBaseTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testFromBase(int $expected, string $value, int $fromBase): void
    {
        static::assertSame($expected, Math\from_base($value, $fromBase));
    }

    public static function provideData(): array
    {
        return [
            [
                5497,
                '1010101111001',
                2,
            ],
            [
                2_014_587_925_987,
                'pphlmw9v',
                36,
            ],
            [
                Math\INT32_MAX,
                'zik0zj',
                36,
            ],
            [
                15,
                'F',
                16,
            ],
        ];
    }

    public function testInvalidDigitThrows(): void
    {
        $this->expectException(Math\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid digit Z in base 16');

        Math\from_base('Z', 16);
    }

    public function testSpecialCharThrows(): void
    {
        $this->expectException(Math\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid digit * in base 16');

        Math\from_base('*', 16);
    }

    #[DataProvider('provideDigitEqualToBase')]
    public function testDigitEqualToBaseThrows(string $number, string $digit, int $fromBase): void
    {
        $this->expectException(Math\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid digit ' . $digit . ' in base ' . $fromBase);

        Math\from_base($number, $fromBase);
    }

    public static function provideDigitEqualToBase(): iterable
    {
        yield 'base 2' => ['2', '2', 2];
        yield 'base 8' => ['8', '8', 8];
        yield 'base 10' => ['a', 'a', 10];
        yield 'base 16 lowercase' => ['g', 'g', 16];
        yield 'base 16 uppercase' => ['G', 'G', 16];
        yield 'base 35 trailing digit' => ['1z', 'z', 35];
    }

    public function testHighestDigitIsAccepted(): void
    {
        static::assertSame(1, Math\from_base('1', 2));
        static::assertSame(15, Math\from_base('f', 16));
        static::assertSame(35, Math\from_base('Z', 36));
    }

    public function testThrowsForOverflow(): void
    {
        $number = Str\repeat('A', 100);

        $this->expectException(Math\Exception\OverflowException::class);
        $this->expectExceptionMessage('Unexpected integer overflow parsing ' . $number . ' from base 32');

        Math\from_base($number, 32);
    }
}
