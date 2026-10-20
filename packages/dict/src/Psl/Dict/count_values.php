<?php

declare(strict_types=1);

namespace Psl\Dict;

use function array_count_values;
use function is_array;
use function iterator_to_array;

/**
 * Returns a new dict mapping each value to the number of times it appears
 * in the given array.
 *
 * @template T of array-key
 *
 * @param iterable<T> $values
 *
 * @return array<T, int>
 *
 * @api
 */
function count_values(iterable $values): array
{
    if (!is_array($values)) {
        // The keys are not used: preserving them would drop the values of repeated keys.
        $values = iterator_to_array($values, preserve_keys: false);
    }

    return array_count_values($values);
}
