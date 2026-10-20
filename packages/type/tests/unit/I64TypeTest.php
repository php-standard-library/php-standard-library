<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use Override;
use Psl\Math;
use Psl\Type;

use const INF;
use const NAN;

final class I64TypeTest extends TypeTestCase
{
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\i64();
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        yield [123, 123];
        yield [0, 0];
        yield ['0', 0];
        yield ['123', 123];
        yield [static::stringable('123'), 123];
        yield ['7', 7];
        yield ['07', 7];
        yield ['007', 7];
        yield ['-107', -107];
        yield ['000', 0];
        yield [1.0, 1];
        yield [static::stringable((string) Math\INT16_MIN), Math\INT16_MIN];
        yield [static::stringable((string) Math\INT16_MAX), Math\INT16_MAX];
        yield [static::stringable('-321'), -321];
        yield ['-321', -321];
        yield [-321, -321];
        yield [static::stringable((string) Math\INT32_MIN), Math\INT32_MIN];
        yield [static::stringable((string) Math\INT32_MAX), Math\INT32_MAX];
        yield [static::stringable((string) Math\INT64_MAX), Math\INT64_MAX];
        yield [(string) Math\INT64_MAX, Math\INT64_MAX];
        yield [Math\INT64_MAX, Math\INT64_MAX];
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
        yield [static::getType(), 'i64'];
    }

    public function testItIsAMemoizedType(): void
    {
        static::assertSame(Type\i64(), Type\i64());
    }
}
