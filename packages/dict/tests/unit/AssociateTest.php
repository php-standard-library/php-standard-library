<?php

declare(strict_types=1);

namespace Psl\Dict\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Collection;
use Psl\Dict;
use stdClass;

final class AssociateTest extends TestCase
{
    public function testAssociate(): void
    {
        static::assertSame(['a' => 1, 'b' => 2, 'c' => 3], Dict\associate(['a', 'b', 'c'], [1, 2, 3]));
    }

    public function testAssociateEmpty(): void
    {
        static::assertSame([], Dict\associate([], []));
    }

    public function testAssociateCollections(): void
    {
        static::assertSame(
            ['a' => 1, 'b' => 2, 'c' => 3],
            Dict\associate(Collection\Vector::fromArray(['a', 'b', 'c']), Collection\Vector::fromArray([1, 2, 3])),
        );
    }

    public function testAssociateIterablesWithRepeatedKeys(): void
    {
        $keys = (static function (): iterable {
            yield from ['a', 'b'];
            yield from ['c', 'd'];
        })();
        $values = (static function (): iterable {
            yield from [1, 2];
            yield from [3, 4];
        })();

        static::assertSame(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], Dict\associate($keys, $values));
    }

    public function testAssociateIterableWithRepeatedKeysAndArray(): void
    {
        $keys = (static function (): iterable {
            yield from ['a', 'b'];
            yield from ['c', 'd'];
        })();

        static::assertSame(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], Dict\associate($keys, [1, 2, 3, 4]));
    }

    public function testAssociateIterablesWithNonArrayKeys(): void
    {
        $keys = (static function (): iterable {
            yield new stdClass() => 'a';
            yield new stdClass() => 'b';
        })();

        static::assertSame(['a' => 1, 'b' => 2], Dict\associate($keys, [1, 2]));
    }

    public function testAssociateWithMissingKeys(): void
    {
        $this->expectException(Dict\Exception\LogicException::class);
        $this->expectExceptionMessage('Expected length of $keys and $values to be the same');

        Dict\associate(['a', 'b', 'c'], [1, 2, 3, 4]);
    }

    public function testAssociateWithMissingValues(): void
    {
        $this->expectException(Dict\Exception\LogicException::class);
        $this->expectExceptionMessage('Expected length of $keys and $values to be the same');

        Dict\associate(['a', 'b', 'c', 'd', 'e', 'f'], [1, 2, 3, 4]);
    }
}
