<?php

declare(strict_types=1);

namespace Psl\Iter;

use function count as php_count;
use function is_array;
use function is_countable;

/**
 * Returns true if the given iterable is empty.
 *
 * Arrays and `Countable` iterables are checked without iterating them. A {@see Iterator} is
 * checked for a first element without counting it and without moving its cursor. Any other
 * iterable is advanced to its first element at most, so infinite iterables are supported.
 *
 * @template T
 *
 * @param iterable<T> $iterable
 *
 * @return ($iterable is non-empty-array|non-empty-list ? false : true)
 *
 * @psalm-assert-if-true empty $iterable
 *
 * @mago-expect lint:loop-does-not-iterate
 *
 * @api
 */
function is_empty(iterable $iterable): bool
{
    if (is_array($iterable)) {
        return [] === $iterable;
    }

    if (is_countable($iterable)) {
        if ($iterable instanceof Iterator) {
            // Counting an `Iterator` pulls every remaining element out of its generator. It keeps the
            // elements it has pulled, so a copy can be rewound to the first element, pulling at most
            // that one, without moving the cursor of the original.
            $copy = clone $iterable;
            $copy->rewind();

            return !$copy->valid();
        }

        return 0 === php_count($iterable);
    }

    foreach ($iterable as $_) {
        return false;
    }

    return true;
}
