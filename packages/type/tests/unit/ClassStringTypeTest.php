<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use Exception;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psl\Collection;
use Psl\Type;
use Throwable;

final class ClassStringTypeTest extends TypeTestCase
{
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\class_string(Collection\CollectionInterface::class);
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        yield [$_ = Collection\Vector::class, $_];
        yield [$_ = Collection\MutableVector::class, $_];
        yield [$_ = Collection\Map::class, $_];
        yield [$_ = Collection\MutableMap::class, $_];
        yield [$_ = Collection\MutableMapInterface::class, $_];
        yield [$_ = Collection\CollectionInterface::class, $_];
    }

    #[Override]
    public static function getInvalidCoercions(): iterable
    {
        yield [null];
        yield [STDIN];
        yield ['UnknownClass'];
        yield [static::stringable('foo')];
        yield [new class {}];
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [Type\class_string(Collection\MapInterface::class), 'class-string<Psl\Collection\MapInterface>'];
        yield [Type\class_string(Collection\VectorInterface::class), 'class-string<Psl\Collection\VectorInterface>'];
        yield [Type\class_string(Collection\Vector::class), 'class-string<Psl\Collection\Vector>'];
        yield [Type\class_string(Collection\Map::class), 'class-string<Psl\Collection\Map>'];
        yield [Type\class_string(), 'class-string'];
    }

    public static function validValuesForUnrestrictedType(): iterable
    {
        yield [Collection\Vector::class];
        yield [Collection\MutableVector::class];
        yield [Collection\Map::class];
        yield [Collection\MutableMap::class];
        yield [Exception::class];
        yield [Collection\MutableMapInterface::class];
        yield [Collection\CollectionInterface::class];
        yield [Throwable::class];
    }

    #[DataProvider('validValuesForUnrestrictedType')]
    public function testUnspecifiedTypeAcceptsAnyClassString(string $value): void
    {
        static::assertSame($value, Type\class_string()->assert($value));
    }

    /** @return iterable<array{0: mixed}> */
    public static function invalidValuesForUnspecifiedType(): iterable
    {
        yield from self::getInvalidCoercions();
    }

    #[DataProvider('invalidValuesForUnspecifiedType')]
    public function testInvalidValuesWhenTypeIsUnspecified(mixed $value): void
    {
        $this->expectException(Type\Exception\AssertException::class);
        Type\class_string()->assert($value);
    }
}
