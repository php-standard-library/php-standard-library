<?php

declare(strict_types=1);

namespace Psl\Async;

use Throwable;

/**
 * Awaits all awaitables to complete concurrently.
 *
 * @template Tk of array-key
 * @template Tv
 *
 * @param iterable<Tk, Awaitable<Tv>> $awaitables
 *
 * @throws Exception\CompositeException If multiple awaitables failed at once.
 *
 * @return array<Tk, Tv> an array containing the results, preserving the original awaitables order.
 *
 * @api
 */
function all(iterable $awaitables): array
{
    // `$awaitables` is traversed more than once, which a generator does not support, and may repeat
    // keys, which an array does not support: work on a list, and key the results at the end.
    $keys = [];
    $list = [];
    foreach ($awaitables as $key => $awaitable) {
        $keys[] = $key;
        $list[] = $awaitable;
    }

    $values = [];

    // Awaitable::iterate() to throw the first error based on completion order instead of argument order
    foreach (Awaitable::iterate($list) as $index => $awaitable) {
        try {
            $values[$index] = $awaitable->await();
        } catch (Throwable $exception) {
            $errors = [];
            foreach ($list as $original) {
                if ($original === $awaitable) {
                    continue;
                }

                if (!$original->isComplete()) {
                    $original->ignore();

                    continue;
                }

                try {
                    $original->await();
                } catch (Throwable $error) {
                    $errors[] = $error;
                }
            }

            if ([] === $errors) {
                throw $exception;
            }

            throw new Exception\CompositeException(
                [$exception, ...$errors],
                'Multiple exceptions thrown while waiting.',
            );
        }
    }

    $result = [];
    foreach ($keys as $index => $key) {
        $result[$key] = $values[$index];
    }

    return $result;
}
