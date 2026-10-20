<?php

declare(strict_types=1);

namespace Psl\Collection\Tests\Unit;

use Override;
use Psl\Collection\Vector;
use stdClass;

final class VectorTest extends AbstractVectorTestCase
{
    /**
     * The Vector class used for values, keys .. etc.
     *
     * @var class-string<Vector>
     */
    protected string $vectorClass = Vector::class;

    public function testFromItems(): void
    {
        $vector = Vector::fromItems([1, 2, 3]);
        static::assertSame([1, 2, 3], $vector->toArray());
    }

    public function testFromItemsWithDuplicateKeys(): void
    {
        $items = (static function (): iterable {
            yield from [1, 2];
            yield from [3, 4];
        })();

        static::assertSame([1, 2, 3, 4], Vector::fromItems($items)->toArray());
    }

    public function testFromItemsWithNonArrayKeys(): void
    {
        $items = (static function (): iterable {
            yield new stdClass() => 'a';
            yield 1.5 => 'b';
        })();

        static::assertSame(['a', 'b'], Vector::fromItems($items)->toArray());
    }

    /**
     * @template T
     *
     * @param array<T> $items
     *
     * @return Vector<T>
     */
    #[Override]
    protected function create(array $items): Vector
    {
        return new Vector($items);
    }
}
