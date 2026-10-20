<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Stringable;

use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * @extends Type\Type<numeric-string>
 *
 * @internal
 */
final readonly class NumericStringType extends Type\Type
{
    /**
     * @psalm-assert-if-true numeric-string $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        return is_string($value) && is_numeric($value);
    }

    /**
     * @throws CoercionException
     *
     * @return numeric-string
     */
    #[Override]
    public function coerce(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        // NAN and INF are floats, but casting them yields "NAN" / "INF", which are not numeric strings.
        if (is_int($value) || is_float($value) && is_finite($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            $str = (string) $value;
            if (is_numeric($str)) {
                return $str;
            }
        }

        throw CoercionException::withValue($value, $this->toString());
    }

    /**
     * @throws AssertException
     *
     * @return numeric-string
     *
     * @psalm-assert numeric-string $value
     */
    #[Override]
    public function assert(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        throw AssertException::withValue($value, $this->toString());
    }

    #[Override]
    public function toString(): string
    {
        return 'numeric-string';
    }
}
