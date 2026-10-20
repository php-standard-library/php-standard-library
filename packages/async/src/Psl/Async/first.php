<?php

declare(strict_types=1);

namespace Psl\Async;

use function is_array;
use function iterator_to_array;

/**
 * Unwraps the first completed awaitable.
 *
 * If you want the first awaitable completed without an error, use {@see any()} instead.
 *
 * @template T
 *
 * @param iterable<Awaitable<T>> $awaitables
 *
 * @throws Exception\InvalidArgumentException If $awaitables is empty.
 *
 * @return T
 *
 * @api
 */
function first(iterable $awaitables): mixed
{
    // `$awaitables` is traversed more than once, which a generator does not support.
    if (!is_array($awaitables)) {
        $awaitables = iterator_to_array($awaitables, preserve_keys: false);
    }

    foreach (Awaitable::iterate($awaitables) as $first) {
        foreach ($awaitables as $awaitable) {
            if ($awaitable === $first) {
                continue;
            }

            $awaitable->ignore();
        }

        return $first->await();
    }

    throw new Exception\InvalidArgumentException('$awaitables must be a non-empty-iterable.');
}
