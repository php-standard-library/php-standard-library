<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use JsonException;
use Override;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Throwable;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * @template T
 *
 * @extends Type\Type<T>
 *
 * @internal
 */
final readonly class JsonDecodedType extends Type\Type
{
    /**
     * @psalm-mutation-free
     *
     * @param Type\TypeInterface<T> $inner
     */
    public function __construct(
        private Type\TypeInterface $inner,
    ) {}

    /**
     * @throws CoercionException
     *
     * @return T
     */
    #[Override]
    public function coerce(mixed $value): mixed
    {
        if ($this->inner->matches($value)) {
            return $value;
        }

        if (!is_string($value)) {
            // `matches()` returns `false` for a value it can not inspect without consuming it, such as a
            // `Generator`, which `assert()` still accepts, returning a validated copy of it. Any failure is a
            // coercion failure: a `Traversable` that `matches()` already iterated may throw when iterated again.
            try {
                return $this->inner->assert($value);
            } catch (Throwable) {
                throw CoercionException::withValue($value, $this->toString());
            }
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CoercionException::withValue(
                $value,
                $this->toString(),
                PathExpression::coerceInput($value, $this->inner->toString()),
                $e,
            );
        }

        try {
            return $this->inner->coerce($decoded);
        } catch (Throwable $e) {
            throw CoercionException::withValue(
                $value,
                $this->toString(),
                PathExpression::coerceOutput($decoded, $this->inner->toString()),
                $e,
            );
        }
    }

    /**
     * @throws AssertException
     *
     * @return T
     *
     * @psalm-assert T $value
     */
    #[Override]
    public function assert(mixed $value): mixed
    {
        return $this->inner->assert($value);
    }

    #[Override]
    public function toString(): string
    {
        return 'json-decoded<' . $this->inner->toString() . '>';
    }
}
