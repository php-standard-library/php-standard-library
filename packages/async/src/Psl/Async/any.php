<?php

declare(strict_types=1);

namespace Psl\Async;

use Psl\Async\Exception\CompositeException;
use Throwable;

use function is_array;
use function iterator_to_array;

/**
 * Unwraps the first successfully completed awaitable.
 *
 * If you want the first awaitable completed, successful or not, use {@see first()} instead.
 *
 * @template T
 *
 * @param iterable<Awaitable<T>> $awaitables
 *
 * @throws Exception\CompositeException If all $awaitables errored.
 * @throws Exception\InvalidArgumentException If $awaitables is empty.
 *
 * @return T
 *
 * @api
 */
function any(iterable $awaitables): mixed
{
    // `$awaitables` is traversed more than once, which a generator does not support.
    if (!is_array($awaitables)) {
        $awaitables = iterator_to_array($awaitables, preserve_keys: false);
    }

    $errors = [];
    foreach (Awaitable::iterate($awaitables) as $first) {
        try {
            $result = $first->await();
            foreach ($awaitables as $awaitable) {
                if ($awaitable === $first) {
                    continue;
                }

                $awaitable->ignore();
            }

            return $result;
        } catch (Throwable $exception) {
            $errors[] = $exception;
        }
    }

    if ([] === $errors) {
        throw new Exception\InvalidArgumentException('$awaitables must be a non-empty-iterable.');
    }

    throw new CompositeException($errors);
}
