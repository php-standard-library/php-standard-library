<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Stringable;

use function is_float;
use function is_int;
use function is_string;
use function ltrim;

use const PHP_INT_MIN;

/**
 * @extends Type\Type<int>
 *
 * @internal
 */
final readonly class IntType extends Type\Type
{
    /**
     * @psalm-assert-if-true int $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        return is_int($value);
    }

    /**
     * @throws CoercionException
     */
    #[Override]
    public function coerce(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        // Casting NAN, INF, or a float outside of [PHP_INT_MIN, PHP_INT_MAX] to int emits a warning since PHP 8.5.
        if (is_float($value) && $value >= (float) PHP_INT_MIN && $value < -(float) PHP_INT_MIN) {
            $integerValue = (int) $value;
            if ((float) $integerValue === $value) {
                return $integerValue;
            }
        }

        if (is_string($value) || $value instanceof Stringable) {
            $str = (string) $value;
            $int = @(int) $str;
            if ($str === (string) $int) {
                return $int;
            }

            $trimmed = ltrim($str, '0');
            $int = @(int) $trimmed;
            if ($trimmed === (string) $int) {
                return $int;
            }

            // Exceptional case "000" -(trim)-> "", but we want to return 0
            if ('' === $trimmed && '' !== $str) {
                return 0;
            }
        }

        throw CoercionException::withValue($value, $this->toString());
    }

    /**
     * @psalm-assert int $value
     *
     * @throws AssertException
     */
    #[Override]
    public function assert(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        throw AssertException::withValue($value, $this->toString());
    }

    #[Override]
    public function toString(): string
    {
        return 'int';
    }
}
