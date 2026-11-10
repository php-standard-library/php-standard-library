<?php

declare(strict_types=1);

namespace Psl\Type;

/**
 * Reads `$key` from the value and validates it against the variant registered for that key's value only,
 * so a failure inside a variant keeps the variant's own path.
 *
 * @pure
 *
 * @template Tk of array-key
 * @template Tv
 *
 * @param non-empty-array<Tk, TypeInterface<Tv>> $variants Maps each discriminator value to the type for that variant.
 *
 * @return TypeInterface<Tv>
 *
 * @api
 */
function discriminated_union(string $key, array $variants): TypeInterface
{
    return new Internal\DiscriminatedUnionType($key, $variants);
}
