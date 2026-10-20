<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use Generator;
use IteratorIterator;
use Override;
use Psl\Dict;
use Psl\Iter;
use Psl\Math;
use Psl\Type;
use Psl\Type\Exception\CoercionException;

final class AlwaysAssertTypeTest extends TypeTestCase
{
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\always_assert(Type\int());
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        yield [123, 123];
        yield [0, 0];
        yield [Math\INT64_MAX, Math\INT64_MAX];
        yield [-321, -321];
    }

    #[Override]
    public static function getInvalidCoercions(): iterable
    {
        yield [1.23];
        yield ['1.23'];
        yield ['1e123'];
        yield [''];
        yield [[]];
        yield [[123]];
        yield [null];
        yield [false];
        yield [static::stringable('1.23')];
        yield [static::stringable('-007')];
        yield ['-007'];
        yield ['9223372036854775808'];
        yield [static::stringable('9223372036854775808')];
        yield ['-9223372036854775809'];
        yield [static::stringable('-9223372036854775809')];
        yield ['0xFF'];
        yield [''];
        yield ['123'];
        yield ['0'];
        yield [static::stringable('123')];
        yield [static::stringable((string) Math\INT16_MAX)];
        yield [static::stringable((string) Math\INT64_MAX)];
        yield [(string) Math\INT64_MAX];
        yield [static::stringable('-321')];
        yield ['-321'];
        yield ['7'];
        yield ['07'];
        yield ['007'];
        yield ['000'];
        yield [1.0];
    }

    public function testCoercesGeneratorsByAssertingThem(): void
    {
        $type = Type\always_assert(Type\iterable(Type\int(), Type\string()));

        $coerced = $type->coerce((static fn(): iterable => yield from [1 => 'a', 2 => 'b'])());

        static::assertInstanceOf(Iter\Iterator::class, $coerced);
        static::assertSame([1 => 'a', 2 => 'b'], Dict\from_iterable($coerced));
        // values that match are still returned as they are.
        static::assertSame([1 => 'a'], $type->coerce([1 => 'a']));
    }

    public function testDoesNotCoerceInvalidGenerators(): void
    {
        $type = Type\always_assert(Type\iterable(Type\int(), Type\int()));

        $this->expectException(CoercionException::class);
        $this->expectExceptionMessage('Could not coerce "Generator" to type "iterable<int, int>".');

        $type->coerce((static fn(): iterable => yield 1 => '1')());
    }

    public function testDoesNotCoerceInvalidTraversablesThatCanNotBeIteratedTwice(): void
    {
        $type = Type\always_assert(Type\iterable(Type\int(), Type\int()));

        $this->expectException(CoercionException::class);
        $this->expectExceptionMessage('Could not coerce "IteratorIterator" to type "iterable<int, int>".');

        $type->coerce(new IteratorIterator((static fn(): Generator => yield from [1, 'x', 3])()));
    }

    public function testDoesNotCoerceStartedGenerators(): void
    {
        $type = Type\always_assert(Type\iterable(Type\int(), Type\int()));
        $generator = (static fn(): Generator => yield from [1, 2, 3])();
        $generator->next();

        $this->expectException(CoercionException::class);
        $this->expectExceptionMessage('Could not coerce "Generator" to type "iterable<int, int>".');

        $type->coerce($generator);
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [Type\always_assert(Type\int()), 'int'];
        yield [Type\always_assert(Type\string()), 'string'];
        yield [Type\always_assert(Type\bool()), 'bool'];
    }
}
