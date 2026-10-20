<?php

declare(strict_types=1);

namespace Psl\Iter\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Iter;

use function iterator_to_array;

final class IsEmptyTest extends TestCase
{
    #[DataProvider('provideData')]
    public function testIsEmpty(bool $expected, iterable $iterable): void
    {
        static::assertSame($expected, Iter\is_empty($iterable));
    }

    public static function provideData(): iterable
    {
        yield [true, []];
        yield [true, Iter\to_iterator([])];
        yield [true, (static fn(): iterable => yield from [])()];

        yield [false, [null]];
        yield [false, [false]];
        yield [false, ['hello', 'world']];
        yield [false, Iter\to_iterator([null])];
        yield [false, (static fn(): iterable => yield null)()];
    }

    public function testIsEmptyDoesNotExhaustInfiniteIterables(): void
    {
        $infinite = (static function (): iterable {
            $i = 0;
            while (true) {
                yield $i++;
            }
        })();

        static::assertFalse(Iter\is_empty($infinite));
    }

    public function testIsEmptyOnlyAdvancesToTheFirstElement(): void
    {
        $pulled = 0;
        $generator = (static function () use (&$pulled): iterable {
            foreach ([1, 2, 3] as $value) {
                ++$pulled;
                yield $value;
            }
        })();

        static::assertFalse(Iter\is_empty($generator));
        static::assertSame(1, $pulled);
        static::assertTrue($generator->valid());
        static::assertSame(1, $generator->current());
    }

    public function testIsEmptyDoesNotExhaustInfiniteLazyIterators(): void
    {
        $infinite = Iter\Iterator::from(static function (): iterable {
            $i = 0;
            while (true) {
                yield $i++;
            }
        });

        static::assertFalse(Iter\is_empty($infinite));
    }

    public function testIsEmptyOnlyPullsTheFirstElementOfLazyIterators(): void
    {
        $pulled = 0;
        $iterator = Iter\Iterator::from(static function () use (&$pulled): iterable {
            foreach ([1, 2, 3] as $value) {
                ++$pulled;
                yield $value;
            }
        });

        static::assertFalse(Iter\is_empty($iterator));
        static::assertSame(1, $pulled);
        static::assertSame([1, 2, 3], iterator_to_array($iterator));
        static::assertSame(3, $pulled);
    }

    public function testIsEmptyDoesNotMoveTheCursorOfLazyIterators(): void
    {
        $pulled = 0;
        $iterator = Iter\Iterator::from(static function () use (&$pulled): iterable {
            foreach ([1, 2, 3] as $value) {
                ++$pulled;
                yield $value;
            }
        });

        static::assertSame(1, $iterator->current());
        $iterator->next();
        static::assertSame(2, $iterator->current());

        static::assertFalse(Iter\is_empty($iterator));
        static::assertSame(2, $pulled);
        static::assertSame(2, $iterator->current());
        static::assertSame(1, $iterator->key());

        $iterator->next();
        static::assertSame(3, $iterator->current());
    }

    public function testIsEmptyOnEmptyLazyIterators(): void
    {
        $iterator = Iter\Iterator::from(static fn(): iterable => yield from []);

        static::assertTrue(Iter\is_empty($iterator));
        static::assertTrue(Iter\is_empty($iterator));
        static::assertSame([], iterator_to_array($iterator));
        static::assertSame(0, $iterator->count());
    }

    public function testIsEmptyUsesCountable(): void
    {
        $iterator = new ArrayIterator([1, 2, 3]);
        $iterator->next();

        static::assertFalse(Iter\is_empty($iterator));
        // the iterator was not rewound.
        static::assertSame(2, $iterator->current());
        static::assertTrue(Iter\is_empty(new ArrayIterator([])));
    }
}
