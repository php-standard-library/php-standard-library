<?php

declare(strict_types=1);

namespace Psl\Dict\Tests\Unit;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Collection;
use Psl\Dict;
use Psl\Iter;
use Psl\Vec;

use function is_float;
use function is_nan;
use function restore_error_handler;
use function set_error_handler;

use const INF;
use const NAN;

final class UniqueScalarTest extends TestCase
{
    public function testUniqueScalars(): void
    {
        $array = Vec\fill(10, 'foo');
        $array[] = 'bar';

        $unique = Dict\unique_scalar($array);

        static::assertCount(2, $unique);
        static::assertSame(['foo', 'bar'], Vec\values($unique));
    }

    public function testUniqueIterator(): void
    {
        $array = Iter\Iterator::create(['foo', 'foo', 'bar', 'bar', 'baz']);

        $unique = Dict\unique_scalar($array);

        static::assertCount(3, $unique);
        static::assertSame(['foo', 'bar', 'baz'], Vec\values($unique));
    }

    public function testUniqueIteratorAgggregate(): void
    {
        $array = Collection\Map::fromArray(['foo', 'foo', 'bar', 'bar', 'baz']);

        $unique = Dict\unique_scalar($array);

        static::assertCount(3, $unique);
        static::assertSame(['foo', 'bar', 'baz'], Vec\values($unique));
    }

    /**
     * @param array<array-key, scalar> $expected
     * @param array<array-key, scalar> $values
     */
    #[DataProvider('provideStrictData')]
    public function testArraysAndIterablesUseStrictIdentity(array $expected, array $values): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            $fromArray = Dict\unique_scalar($values);
            $fromIterator = Dict\unique_scalar(Iter\Iterator::create($values));
            $fromGenerator = Dict\unique_scalar((static fn(): iterable => yield from $values)());
        } finally {
            restore_error_handler();
        }

        static::assertSame(self::normalize($expected), self::normalize($fromArray));
        static::assertSame(self::normalize($expected), self::normalize($fromIterator));
        static::assertSame(self::normalize($expected), self::normalize($fromGenerator));
    }

    public static function provideStrictData(): iterable
    {
        yield 'int, numeric string, bool and float are distinct' => [
            ['a' => 1, 'b' => '1', 'c' => true, 'd' => 1.0],
            ['a' => 1, 'b' => '1', 'c' => true, 'd' => 1.0, 'e' => 1, 'f' => '1', 'g' => true, 'h' => 1.0],
        ];
        yield 'falsy values are distinct' => [
            [0 => 0, 1 => '0', 2 => false, 3 => '', 4 => 0.0],
            [0, '0', false, '', 0.0, 0, '0', false, ''],
        ];
        yield 'numeric strings are compared as strings' => [
            ['1', '01', '1.0', ' 1', '1e0'],
            ['1', '01', '1.0', ' 1', '1e0', '1'],
        ];
        yield 'floats are compared exactly' => [
            ['x' => 0.1 + 0.2, 'y' => 0.3],
            ['x' => 0.1 + 0.2, 'y' => 0.3, 'z' => 0.3],
        ];
        yield 'negative zero equals zero, first key is kept' => [
            ['neg' => -0.0],
            ['neg' => -0.0, 'pos' => 0.0],
        ];
        yield 'infinities' => [
            [0 => INF, 1 => -INF, 3 => 'INF'],
            [INF, -INF, INF, 'INF', -INF],
        ];
        yield 'NAN values are deduplicated without warnings' => [
            [0 => NAN, 1 => 1.0, 3 => 'NAN'],
            [NAN, 1.0, NAN, 'NAN', -NAN],
        ];
    }

    /**
     * NAN !== NAN, so it can not be compared with assertSame().
     *
     * @param array<array-key, scalar> $values
     *
     * @return array<array-key, scalar>
     */
    private static function normalize(array $values): array
    {
        return Dict\map($values, static fn(mixed $v): mixed => is_float($v) && is_nan($v) ? 'NAN<float>' : $v);
    }
}
