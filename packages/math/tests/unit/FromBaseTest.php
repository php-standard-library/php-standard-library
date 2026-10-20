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

    #[DataProvider('provideInt64Boundaries')]
    public function testParsesInt64Max(string $max, string $maxPlusOne, int $fromBase): void
    {
        static::assertSame(Math\INT64_MAX, Math\from_base($max, $fromBase));
        static::assertSame(Math\INT64_MAX, Math\from_base(Str\uppercase($max), $fromBase));
    }

    #[DataProvider('provideInt64Boundaries')]
    public function testThrowsForInt64MaxPlusOne(string $max, string $maxPlusOne, int $fromBase): void
    {
        $this->expectException(Math\Exception\OverflowException::class);
        $this->expectExceptionMessage('Unexpected integer overflow parsing ' . $maxPlusOne . ' from base ' . $fromBase);

        Math\from_base($maxPlusOne, $fromBase);
    }

    public static function provideInt64Boundaries(): iterable
    {
        yield 'base 2' => [
            '111111111111111111111111111111111111111111111111111111111111111',
            '1000000000000000000000000000000000000000000000000000000000000000',
            2,
        ];
        yield 'base 3' => [
            '2021110011022210012102010021220101220221',
            '2021110011022210012102010021220101220222',
            3,
        ];
        yield 'base 7' => ['22341010611245052052300', '22341010611245052052301', 7];
        yield 'base 10' => ['9223372036854775807', '9223372036854775808', 10];
        yield 'base 16' => ['7fffffffffffffff', '8000000000000000', 16];
        yield 'base 36' => ['1y2p0ij32e8e7', '1y2p0ij32e8e8', 36];
    }

    public function testThrowsForOverflowOnLastDigit(): void
    {
        $this->expectException(Math\Exception\OverflowException::class);
        $this->expectExceptionMessage('Unexpected integer overflow parsing 9223372036854775809 from base 10');

        Math\from_base('9223372036854775809', 10);
    }

    public function testThrowsForOverflow(): void
    {
        $number = Str\repeat('A', 100);

        $this->expectException(Math\Exception\OverflowException::class);
        $this->expectExceptionMessage('Unexpected integer overflow parsing ' . $number . ' from base 32');

        Math\from_base($number, 32);
    }
}
