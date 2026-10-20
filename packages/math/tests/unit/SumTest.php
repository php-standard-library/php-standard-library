<?php

declare(strict_types=1);

namespace Psl\Math\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Math;
use Psl\Vec;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class SumTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testSum(int $expected, array $numbers): void
    {
        static::assertSame($expected, Math\sum($numbers));
    }

    public static function provideData(): array
    {
        return [
            [
                60,
                [
                    10,
                    5,
                    ...Vec\range(0, 9),
                ],
            ],
            [
                103,
                [
                    18,
                    15,
                    ...Vec\range(0, 10),
                    15,
                ],
            ],
            [
                534,
                [
                    178,
                    15,
                    ...Vec\range(0, 45, 5),
                    52,
                    64,
                ],
            ],
            [0, []],
            [PHP_INT_MAX, [PHP_INT_MAX]],
            [PHP_INT_MIN, [PHP_INT_MIN]],
            [-1, [PHP_INT_MAX, PHP_INT_MIN]],
            [PHP_INT_MAX, [PHP_INT_MAX - 1, 1]],
            [PHP_INT_MIN, [PHP_INT_MIN + 1, -1]],
            // Intermediate results overflow, but the final sum fits in an integer.
            [PHP_INT_MAX, [PHP_INT_MAX, 1, -1]],
            [PHP_INT_MAX - 1, [PHP_INT_MAX, 1, -2]],
            [PHP_INT_MIN, [PHP_INT_MIN, -1, 1]],
            [PHP_INT_MIN + 1, [PHP_INT_MIN, -1, 2]],
            [-2, [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN]],
            [PHP_INT_MAX, [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, 2]],
            [2, [PHP_INT_MIN, PHP_INT_MIN, -1, PHP_INT_MAX, PHP_INT_MAX, 3, 2]],
        ];
    }

    #[DataProvider('provideOverflowData')]
    public function testSumThrowsOnOverflow(array $numbers): void
    {
        $this->expectException(Math\Exception\OverflowException::class);
        $this->expectExceptionMessage('Unexpected integer overflow while computing the sum of the given numbers.');

        Math\sum($numbers);
    }

    public static function provideOverflowData(): iterable
    {
        yield [[PHP_INT_MAX, 1]];
        yield [[PHP_INT_MIN, -1]];
        yield [[1, PHP_INT_MAX]];
        yield [[PHP_INT_MAX, PHP_INT_MAX]];
        yield [[PHP_INT_MIN, PHP_INT_MIN]];
        yield [[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX]];
        yield [[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, 2]];
        yield [[PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, -2]];
        yield [[PHP_INT_MAX, 1, -1, 1]];
    }
}
