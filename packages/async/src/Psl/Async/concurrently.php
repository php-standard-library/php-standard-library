<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;

/**
 * Run the functions in the tasks' iterable concurrently, without waiting until the previous function has completed.
 *
 * No function and no failure is dropped when `$tasks` repeats a key: each function runs, and its failure is
 * handled as it would be under a distinct key. On success, the result of the last function with that key is
 * returned under it.
 *
 * @template Tk of array-key
 * @template Tv
 *
 * @param iterable<Tk, (Closure(): Tv)> $tasks
 *
 * @throws Exception\CompositeException If multiple functions failed at once.
 *
 * @return array<Tk, Tv> an array containing the results, preserving the original functions order.
 *
 * @api
 */
function concurrently(iterable $tasks): array
{
    // `$tasks` may repeat keys, which an array does not support: keep the started tasks in a list, and key the
    // results at the end.
    $keys = [];
    $awaitables = [];
    foreach ($tasks as $key => $task) {
        $keys[] = $key;
        $awaitables[] = namespace\run($task);
    }

    $values = namespace\all($awaitables);

    $result = [];
    foreach ($keys as $index => $key) {
        $result[$key] = $values[$index];
    }

    return $result;
}
