<?php

declare(strict_types=1);

namespace Psl\Vec\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Vec;

final class IntersectTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testIntersect(array $expected, iterable $first, iterable $second, iterable ...$rest): void
    {
        static::assertSame($expected, Vec\intersect($first, $second, ...$rest));
    }

    public static function provideData(): iterable
    {
        yield [[], [], [], []];
        yield [[], [], [1, 2, 3]];
        yield [[], [], [1, 2, 3], []];
        yield [[], [], [1, 2, 3], [], [4, 5]];

        yield [[], [1, 2], [], []];
        yield [[], [1, 2], ['foo' => 2], []];
        yield [[], [1, 2], [], ['baz' => 1]];
        yield [[], [1, 2], ['foo' => 2], ['baz' => 1]];
        yield [[1, 2], [1, 2], ['foo' => 2, 'baz' => 1]];
        yield [[1, 2, 3, 4, 5, 6], Vec\range(1, 8), Vec\range(1, 6)];
        yield [[], Vec\range(1, 8), Vec\range(1, 6), []];
        yield [[6], Vec\range(1, 8), Vec\range(1, 6), [5 => 6, 6 => 7]];
        yield [[2, 3], ['a' => 1, 'b' => 2, 'c' => 3], [3, 2], [2, 3, 4]];
        yield [['bar', 'baz'], [3 => 'foo', 7 => 'bar', 9 => 'baz'], ['baz', 'bar']];
        yield [[2, 2], [1, 2, 2], [2]];
        yield [[3], new ArrayIterator(['x' => 1, 'y' => 3]), new ArrayIterator([3]), (static fn() => yield 3)()];
    }
}
