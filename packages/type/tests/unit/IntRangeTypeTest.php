<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psl\Exception\InvariantViolationException;
use Psl\Math;
use Psl\Type;

use const INF;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class IntRangeTypeTest extends TypeTestCase
{
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\int_range(Math\INT64_MIN, Math\INT64_MAX);
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        yield [42, 42];
        yield [-42, -42];
        yield [0, 0];
        yield ['0', 0];
        yield ['42', 42];
        yield ['-42', -42];
        yield [static::stringable('42'), 42];
        yield [static::stringable((string) Math\INT64_MAX), Math\INT64_MAX];
        yield [(string) Math\INT64_MAX, Math\INT64_MAX];
        yield [Math\INT64_MAX, Math\INT64_MAX];
        yield ['7', 7];
        yield ['07', 7];
        yield ['007', 7];
        yield ['000', 0];
        yield [1.0, 1];
    }

    #[Override]
    public static function getInvalidCoercions(): iterable
    {
        yield [1.23];
        yield [-(float) Math\INT64_MIN]; // 2^63, the smallest float above Math\INT64_MAX
        yield [(float) Math\INT64_MIN - 2048.0]; // the largest float below Math\INT64_MIN
        yield [1e30];
        yield [-1e30];
        yield [INF];
        yield [-INF];
        yield [NAN];
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
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [Type\int_range(5, 10), 'int<5, 10>'];
        yield [Type\int_range(PHP_INT_MIN, PHP_INT_MAX), 'int<min, max>'];
        yield [Type\int_range(99, PHP_INT_MAX), 'int<99, max>'];
        yield [Type\int_range(PHP_INT_MIN, 42), 'int<min, 42>'];
    }

    public static function outOfBoundsProvider(): iterable
    {
        yield [123];
        yield ['123'];
        yield [-10];
        yield ['-10'];
    }

    #[DataProvider('outOfBoundsProvider')]
    public function testCoerceWithLowerBounds(mixed $value): void
    {
        $type = Type\int_range(0, 100);
        $this->expectException(Type\Exception\CoercionException::class);
        $this->expectExceptionMessage('int<0, 100>');
        $type->coerce($value);
    }

    #[DataProvider('outOfBoundsProvider')]
    public function testMatchesWithLowerBounds(mixed $value): void
    {
        $type = Type\int_range(0, 100);
        static::assertFalse($type->matches($value));
    }

    #[DataProvider('outOfBoundsProvider')]
    public function testAssertWithLowerBounds(mixed $value): void
    {
        $type = Type\int_range(0, 100);
        $this->expectException(Type\Exception\AssertException::class);
        $type->assert($value);
    }

    public function testSingleValueRange(): void
    {
        $type = Type\int_range(5, 5);

        static::assertTrue($type->matches(5));
        static::assertFalse($type->matches(4));
        static::assertFalse($type->matches(6));
        static::assertSame('int<5, 5>', $type->toString());
    }

    public function testMinGreaterThanMaxThrows(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessage('Expected $min (10) to be less than or equal to $max (5).');

        Type\int_range(10, 5);
    }
}
