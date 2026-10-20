<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use Override;
use Psl\Type;

use const INF;
use const NAN;

/**
 * @extends TypeTestCase<numeric-string>
 */
final class NumericStringTypeTest extends TypeTestCase
{
    /**
     * @return Type\Type<numeric-string>
     */
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\numeric_string();
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        yield [123, '123'];
        yield [0, '0'];
        yield [1.0, '1'];
        yield [1.23, '1.23'];
        yield [1e100, '1.0E+100'];
        yield [-0.0, '-0'];
        yield ['0', '0'];
        yield ['123', '123'];
        yield ['1e23', '1e23'];
        yield [static::stringable('123'), '123'];
    }

    #[Override]
    public static function getInvalidCoercions(): iterable
    {
        yield [''];
        yield ['hello'];
        yield [static::stringable('hello')];
        yield [[]];
        yield [[1]];
        yield [Type\bool()];
        yield [null];
        yield [false];
        yield [true];
        yield [STDIN];
        // Non-finite floats would be cast to "NAN" / "INF" / "-INF", which are not numeric strings.
        yield [NAN];
        yield [INF];
        yield [-INF];
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [static::getType(), 'numeric-string'];
    }

    public function testItIsAMemoizedType(): void
    {
        static::assertSame(Type\numeric_string(), Type\numeric_string());
    }
}
