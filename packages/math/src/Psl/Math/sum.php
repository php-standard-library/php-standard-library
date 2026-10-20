<?php

declare(strict_types=1);

namespace Psl\Math;

use function array_sum;
use function is_int;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Returns the sum of all the given numbers.
 *
 * @param list<int> $numbers
 *
 * @pure
 *
 * @throws Exception\OverflowException If the sum is greater than Math\INT64_MAX or less than Math\INT64_MIN.
 *
 * @api
 */
function sum(array $numbers): int
{
    /** @var int|float $sum - array_sum() returns a float once the integer sum overflows. */
    $sum = array_sum($numbers);
    if (is_int($sum)) {
        return $sum;
    }

    // array_sum() switches to float arithmetic once an intermediate result overflows, which loses precision.
    // Redo the sum with wrap-around arithmetic and count the wraps: the result is exact when they cancel out.
    $sum = 0;
    $wraps = 0;
    foreach ($numbers as $number) {
        if ($number > 0 && $sum > (PHP_INT_MAX - $number)) {
            $sum = $sum + PHP_INT_MIN + ($number + PHP_INT_MIN);
            ++$wraps;
        } elseif ($number < 0 && $sum < (PHP_INT_MIN - $number)) {
            $sum = $sum - PHP_INT_MIN + ($number - PHP_INT_MIN);
            --$wraps;
        } else {
            $sum += $number;
        }
    }

    if (0 !== $wraps) {
        throw new Exception\OverflowException(
            'Unexpected integer overflow while computing the sum of the given numbers.',
        );
    }

    return $sum;
}
