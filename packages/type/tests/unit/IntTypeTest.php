<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use Override;
use Psl\Math;
use Psl\Type;

use const INF;
use const NAN;

final class IntTypeTest extends TypeTestCase
{
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\int();
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        yield [123, 123];
        yield [0, 0];
        yield ['0', 0];
        yield ['123', 123];
        yield [static::stringable('123'), 123];
        yield [static::stringable((string) Math\INT16_MAX), Math\INT16_MAX];
        yield [static::stringable((string) Math\INT64_MAX), Math\INT64_MAX];
        yield [(string) Math\INT64_MAX, Math\INT64_MAX];
        yield [Math\INT64_MAX, Math\INT64_MAX];
        yield [static::stringable('-321'), -321];
        yield ['-321', -321];
        yield [-321, -321];
        yield ['7', 7];
        yield ['07', 7];
        yield ['007', 7];
        yield ['000', 0];
        yield [1.0, 1];
        yield [(float) Math\INT64_MIN, Math\INT64_MIN];
        yield [-(float) Math\INT64_MIN - 1024.0, Math\INT64_MAX - 1023]; // the largest float below 2^63
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
        yield [''];
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [static::getType(), 'int'];
    }

    public function testItIsAMemoizedType(): void
    {
        static::assertSame(Type\int(), Type\int());
    }
}
