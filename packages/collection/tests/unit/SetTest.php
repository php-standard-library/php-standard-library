<?php

declare(strict_types=1);

namespace Psl\Collection\Tests\Unit;

use Override;
use Psl\Collection\Set;
use stdClass;

final class SetTest extends AbstractSetTestCase
{
    /**
     * The Set class used for values, keys .. etc.
     *
     * @var class-string<Set>
     */
    protected string $setClass = Set::class;

    public function testFromItems(): void
    {
        $set = Set::fromItems(['foo', 'bar', 'baz']);

        static::assertTrue($set->contains('foo'));
        static::assertTrue($set->contains('bar'));
        static::assertTrue($set->contains('baz'));
    }

    public function testFromItemsWithDuplicateKeys(): void
    {
        $items = (static function (): iterable {
            yield from ['a', 'b'];
            yield from ['c', 'd'];
        })();

        static::assertSame(['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd'], Set::fromItems($items)->toArray());
    }

    public function testFromItemsWithNonArrayKeys(): void
    {
        $items = (static function (): iterable {
            yield new stdClass() => 'a';
            yield 1.5 => 'b';
        })();

        static::assertSame(['a' => 'a', 'b' => 'b'], Set::fromItems($items)->toArray());
    }

    /**
     * @template T of array-key
     *
     * @param array<T, mixed> $items
     *
     * @return Set<T>
     */
    #[Override]
    protected function createFromList(array $items): Set
    {
        return Set::fromArray($items);
    }

    public function testFromArrayKeysConstructor(): void
    {
        $set = Set::fromArrayKeys(['foo' => 1, 'bar' => 1, 'baz' => 1]);

        static::assertCount(3, $set);
        static::assertTrue($set->contains('foo'));
        static::assertTrue($set->contains('bar'));
        static::assertTrue($set->contains('baz'));
    }

    #[Override]
    public function testJsonSerialize(): void
    {
        $set = $this->createFromList(['foo', 'bar', 'baz', 'qux']);

        static::assertSame(['foo', 'bar', 'baz', 'qux'], $set->jsonSerialize());
    }
}
