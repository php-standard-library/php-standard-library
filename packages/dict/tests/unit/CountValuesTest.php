<?php

declare(strict_types=1);

namespace Psl\Dict\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Collection;
use Psl\Dict;
use Psl\Vec;
use stdClass;

final class CountValuesTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testCountValues(array $expected, iterable $iterable): void
    {
        static::assertSame($expected, Dict\count_values($iterable));
    }

    public static function provideData(): array
    {
        return [
            [
                ['foo' => 1],
                ['foo'],
            ],
            [
                ['foo' => 2, 'bar' => 1, 'baz' => 5],
                ['foo', 'bar', 'baz', 'foo', 'baz', 'baz', 'baz', 'baz'],
            ],
            [
                ['foo' => 2, 'bar' => 1, 'baz' => 5],
                Collection\Vector::fromArray(['foo', 'bar', 'baz', 'foo', 'baz', 'baz', 'baz', 'baz']),
            ],
            [
                ['foo' => 2, 'bar' => 1, 'baz' => 4],
                Vec\concat(['foo', 'bar', 'baz'], ['foo'], ['baz'], ['baz', 'baz']),
            ],
        ];
    }

    public function testCountValuesOfIterableWithRepeatedKeys(): void
    {
        $values = (static function (): iterable {
            yield from ['a', 'b'];
            yield from ['a', 'a'];
        })();

        static::assertSame(['a' => 3, 'b' => 1], Dict\count_values($values));
    }

    public function testCountValuesOfIterableWithNonArrayKeys(): void
    {
        $values = (static function (): iterable {
            yield new stdClass() => 'a';
            yield 1.5 => 'b';
            yield 1.7 => 'a';
        })();

        static::assertSame(['a' => 2, 'b' => 1], Dict\count_values($values));
    }
}
