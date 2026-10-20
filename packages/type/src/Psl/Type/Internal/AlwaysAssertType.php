<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Throwable;

/**
 * @template T
 *
 * @extends Type\Type<T>
 *
 * @internal
 */
final readonly class AlwaysAssertType extends Type\Type
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

        // `matches()` returns `false` for a value it can not inspect without consuming it, such as a
        // `Generator`, which `assert()` still accepts, returning a validated copy of it. Any failure is a
        // coercion failure: a `Traversable` that `matches()` already iterated may throw when iterated again.
        try {
            return $this->inner->assert($value);
        } catch (Throwable) {
            throw CoercionException::withValue($value, $this->toString());
        }
    }

    /**
     * @psalm-assert T $value
     *
     * @throws AssertException
     *
     * @return T
     */
    #[Override]
    public function assert(mixed $value): mixed
    {
        return $this->inner->assert($value);
    }

    #[Override]
    public function toString(): string
    {
        return $this->inner->toString();
    }
}
