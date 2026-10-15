<?php

declare(strict_types=1);

namespace Psl\Vec;

use function array_intersect;
use function array_map;
use function array_values;

/**
 * Computes the intersection of iterables, returning a list of the values
 * from the first iterable that are present in all other iterables.
 *
 * Keys are not preserved, and values are compared using string
 * representation, like `Dict\intersect()`.
 *
 * Example:
 *
 *      Vec\intersect(['a' => 1, 'b' => 2, 'c' => 3], [3, 2], [2, 3, 4])
 *      => Vec(2, 3)
 *
 * @template T
 *
 * @param iterable<T> $first
 * @param iterable<mixed> $second
 * @param iterable<mixed> ...$rest
 *
 * @return list<T>
 *
 * @api
 */
function intersect(iterable $first, iterable $second, iterable ...$rest): array
{
    return array_values(array_intersect(
        namespace\values($first),
        namespace\values($second),
        ...array_map(namespace\values(...), $rest),
    ));
}
