<?php

declare(strict_types=1);

namespace Psl\Math;

use function ord;
use function sprintf;
use function str_split;

/**
 * Converts the given string in base `$fromBase` to an integer, assuming letters a-z
 * are used for digits when `$fromBase` > 10.
 *
 * @param non-empty-string $number
 * @param int<2, 36> $fromBase
 *
 * @pure
 *
 * @throws Exception\InvalidArgumentException If $number contains an invalid digit in base $fromBase
 * @throws Exception\OverflowException In case of an integer overflow
 *
 * @api
 */
function from_base(string $number, int $fromBase): int
{
    $limit = namespace\div(namespace\INT64_MAX, $fromBase);
    // The largest digit that can still be appended to `$limit` without overflowing.
    $lastDigitLimit = namespace\INT64_MAX - ($limit * $fromBase);
    $result = 0;
    foreach (str_split($number) as $digit) {
        $oval = ord($digit);
        // Branches sorted by guesstimated frequency of use. */
        if (/* '0' - '9' */ $oval <= 57 && $oval >= 48) {
            $dval = $oval - 48;
        } elseif (/* 'a' - 'z' */ $oval >= 97 && $oval <= 122) {
            $dval = $oval - 87;
        } elseif (/* 'A' - 'Z' */ $oval >= 65 && $oval <= 90) {
            $dval = $oval - 55;
        } else {
            $dval = 99;
        }

        if ($dval >= $fromBase) {
            throw new Exception\InvalidArgumentException(sprintf('Invalid digit %s in base %d', $digit, $fromBase));
        }

        // Check before computing: PHP integers do not wrap around, they silently turn into floats.
        if ($result > $limit || $result === $limit && $dval > $lastDigitLimit) {
            throw new Exception\OverflowException(sprintf(
                'Unexpected integer overflow parsing %s from base %d',
                $number,
                $fromBase,
            ));
        }

        $result = ($fromBase * $result) + $dval;
    }

    return $result;
}
