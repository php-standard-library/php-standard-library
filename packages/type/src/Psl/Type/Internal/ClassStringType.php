<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Psl\Type\Type;

use function class_exists;
use function interface_exists;
use function is_a;
use function is_string;

/**
 * @template T as object
 *
 * @extends Type<class-string<T>>
 *
 * @internal
 */
final readonly class ClassStringType extends Type
{
    /**
     * @var class-string<T>|null $classname
     */
    private string|null $classname;

    /**
     * @psalm-mutation-free
     *
     * @param class-string<T>|null $classname
     */
    public function __construct(string|null $classname)
    {
        $this->classname = $classname;
    }

    /**
     * @psalm-assert-if-true class-string<T> $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        if ($this->classname === null) {
            return is_string($value) && (class_exists($value) || interface_exists($value));
        }

        return is_string($value) && is_a($value, $this->classname, true);
    }

    /**
     * @throws CoercionException
     *
     * @return class-string<T>
     */
    #[Override]
    public function coerce(mixed $value): string
    {
        if ($this->matches($value)) {
            return $value;
        }

        throw CoercionException::withValue($value, $this->toString());
    }

    /**
     * @throws AssertException
     *
     * @return class-string<T>
     *
     * @psalm-assert class-string<T> $value
     */
    #[Override]
    public function assert(mixed $value): string
    {
        if ($this->matches($value)) {
            return $value;
        }

        throw AssertException::withValue($value, $this->toString());
    }

    #[Override]
    public function toString(): string
    {
        return $this->classname !== null ? 'class-string<' . $this->classname . '>' : 'class-string';
    }
}
