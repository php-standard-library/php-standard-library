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
 * @extends Type\Type<positive-int>
 *
 * @internal
 */
final readonly class PositiveIntType extends Type\Type
{
    /**
     * @psalm-assert-if-true positive-int $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    /**
     * @throws CoercionException
     *
     * @return positive-int
     */
    #[Override]
    public function coerce(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) || $value instanceof Stringable) {
            $str = (string) $value;
            $int = @(int) $str;
            if ((string) $int === $str && $int > 0) {
                return $int;
            }

            $trimmed = ltrim($str, '0');

            $int = @(int) $trimmed;
            if ((string) $int === $trimmed && $int > 0) {
                return $int;
            }

            throw CoercionException::withValue($value, $this->toString());
        }

        // Casting NAN, INF, or a float outside of [PHP_INT_MIN, PHP_INT_MAX] to int emits a warning since PHP 8.5.
        if (is_float($value) && $value >= (float) PHP_INT_MIN && $value < -(float) PHP_INT_MIN) {
            $integerValue = (int) $value;
            $reconstructed = (float) $integerValue;
            if ($reconstructed === $value && $integerValue > 0) {
                return $integerValue;
            }
        }

        throw CoercionException::withValue($value, $this->toString());
    }

    /**
     * @psalm-assert positive-int $value
     *
     * @throws AssertException
     *
     * @return positive-int
     */
    #[Override]
    public function assert(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw AssertException::withValue($value, $this->toString());
    }

    #[Override]
    public function toString(): string
    {
        return 'positive-int';
    }
}
