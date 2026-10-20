<?php

declare(strict_types=1);

namespace Psl\Vec\Tests\Unit;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Collection;
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

        $unique = Vec\unique_scalar($array);

        static::assertCount(2, $unique);
        static::assertSame(['foo', 'bar'], $unique);
    }

    public function testUniqueIterator(): void
    {
        $array = Iter\Iterator::create(['foo', 'foo', 'bar', 'bar', 'baz']);

        $unique = Vec\unique_scalar($array);

        static::assertCount(3, $unique);
        static::assertSame(['foo', 'bar', 'baz'], $unique);
    }

    public function testUniqueIteratorAgggregate(): void
    {
        $array = Collection\Map::fromArray(['foo', 'foo', 'bar', 'bar', 'baz']);

        $unique = Vec\unique_scalar($array);

        static::assertCount(3, $unique);
        static::assertSame(['foo', 'bar', 'baz'], $unique);
    }

    /**
     * @param list<scalar> $expected
     * @param list<scalar> $values
     */
    #[DataProvider('provideStrictData')]
    public function testArraysAndIterablesUseStrictIdentity(array $expected, array $values): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            $fromArray = Vec\unique_scalar($values);
            $fromIterator = Vec\unique_scalar(Iter\Iterator::create($values));
            $fromGenerator = Vec\unique_scalar((static fn(): iterable => yield from $values)());
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
            [1, '1', true, 1.0],
            [1, '1', true, 1.0, 1, '1', true, 1.0],
        ];
        yield 'falsy values are distinct' => [
            [0, '0', false, '', 0.0],
            [0, '0', false, '', 0.0, 0, '0', false, ''],
        ];
        yield 'numeric strings are compared as strings' => [
            ['1', '01', '1.0', ' 1', '1e0'],
            ['1', '01', '1.0', ' 1', '1e0', '1'],
        ];
        yield 'floats are compared exactly' => [
            [0.1 + 0.2, 0.3],
            [0.1 + 0.2, 0.3, 0.3],
        ];
        yield 'negative zero equals zero' => [
            [0.0],
            [0.0, -0.0],
        ];
        yield 'infinities' => [
            [INF, -INF, 'INF'],
            [INF, -INF, INF, 'INF', -INF],
        ];
        yield 'NAN values are deduplicated without warnings' => [
            [NAN, 1.0, 'NAN'],
            [NAN, 1.0, NAN, 'NAN', -NAN],
        ];
    }

    /**
     * NAN !== NAN, so it can not be compared with assertSame().
     *
     * @param list<scalar> $values
     *
     * @return list<scalar>
     */
    private static function normalize(array $values): array
    {
        return Vec\map($values, static fn(mixed $v): mixed => is_float($v) && is_nan($v) ? 'NAN<float>' : $v);
    }
}
