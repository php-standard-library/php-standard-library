<?php

declare(strict_types=1);

namespace Psl\Type;

/**
 * Returns a type for arrays and `Traversable` values whose keys and values match the given types.
 *
 * `assert()` and `coerce()` iterate the value and return a validated, re-iterable copy of it. `matches()`
 * does not iterate a `Generator`, as it can not be rewound: it returns `false` for a `Generator` unless both
 * the key and the value types are `mixed()`.
 *
 * @pure
 *
 * @template Tk
 * @template Tv
 *
 * @param TypeInterface<Tk> $keyType
 * @param TypeInterface<Tv> $valueType
 *
 * @return TypeInterface<iterable<Tk, Tv>>
 *
 * @api
 */
function iterable(TypeInterface $keyType, TypeInterface $valueType): TypeInterface
{
    return new Internal\IterableType($keyType, $valueType);
}
