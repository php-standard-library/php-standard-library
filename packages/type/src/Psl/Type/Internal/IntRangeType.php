<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl;
use Psl\Exception\InvariantViolationException;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Stringable;

use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function sprintf;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * @extends Type\Type<int>
 *
 * @internal
 */
final readonly class IntRangeType extends Type\Type
{
    /**
     * @throws InvariantViolationException If $min is greater than $max.
     */
    public function __construct(
        private int $min,
        private int $max,
    ) {
        Psl\invariant($min <= $max, 'Expected $min (%d) to be less than or equal to $max (%d).', $min, $max);
    }

    /**
     * @psalm-assert-if-true int $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        return is_int($value) && $value >= $this->min && $value <= $this->max;
    }

    /**
     * @throws CoercionException
     */
    #[Override]
    public function coerce(mixed $value): int
    {
        $int = $this->coerceToInt($value);
        if ($int >= $this->min && $int <= $this->max) {
            return $int;
        }

        throw CoercionException::withValue($value, $this->toString());
    }

    /**
     * @throws CoercionException
     * @return int<min, max>
     */
    private function coerceToInt(mixed $value): int
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
        if (is_int($value) && $value >= $this->min && $value <= $this->max) {
            return $value;
        }

        throw AssertException::withValue($value, $this->toString());
    }

    #[Override]
    public function toString(): string
    {
        $min = PHP_INT_MIN === $this->min ? 'min' : (string) $this->min;
        $max = PHP_INT_MAX === $this->max ? 'max' : (string) $this->max;

        return sprintf('int<%s, %s>', $min, $max);
    }
}
