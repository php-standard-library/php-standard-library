<?php

declare(strict_types=1);

namespace Psl\Iter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Iter;
use stdClass;

use function array_keys;
use function ksort;
use function mt_srand;

final class RandomTest extends TestCase
{
    public function testRandom(): void
    {
        $iterable = [1, 2, 3, 4, 5];
        $value = Iter\random($iterable);

        static::assertTrue(Iter\contains($iterable, $value));

        $iterable = Iter\to_iterator([1, 2, 3, 4, 5]);
        $value = Iter\random($iterable);

        static::assertTrue(Iter\contains($iterable, $value));

        $value = Iter\random([1]);

        static::assertSame(1, $value);
    }

    public function testRandomCanPickEveryValueOfIterableWithRepeatedKeys(): void
    {
        mt_srand(42);

        try {
            $picked = [];
            for ($i = 0; $i < 200; $i++) {
                $iterable = (static function (): iterable {
                    yield from ['a', 'b'];
                    yield from ['c', 'd'];
                })();

                $picked[Iter\random($iterable)] = true;
            }
        } finally {
            mt_srand();
        }

        ksort($picked);

        static::assertSame(['a', 'b', 'c', 'd'], array_keys($picked));
    }

    public function testRandomWithNonArrayKeys(): void
    {
        $iterable = (static function (): iterable {
            yield new stdClass() => 'a';
        })();

        static::assertSame('a', Iter\random($iterable));
    }

    public function testRandomWithEmptyIterator(): void
    {
        $this->expectException(Iter\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a non-empty iterable.');

        Iter\random([]);
    }
}
