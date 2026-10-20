<?php

declare(strict_types=1);

namespace Psl\Vec;

use function is_float;
use function is_int;
use function is_nan;
use function is_string;
use function pack;

/**
 * Returns a new list in which each value appears exactly once. Better performant than `Vec\unique()` when the values
 * are only scalars.
 *
 * Values are compared using strict equality (`===`), so `1`, `'1'`, `1.0`, and `true` are all distinct,
 * while `0.0` and `-0.0` are the same value. As an exception, all `NAN` values are considered equal to each other.
 * The first occurrence of each value is kept. Arrays and other iterables behave the same.
 *
 * @template Tv of scalar
 *
 * @param iterable<Tv> $iterable
 *
 * @return list<Tv>
 *
 * @api
 */
function unique_scalar(iterable $iterable): array
{
    /** @var array<int, true> $ints */
    $ints = [];
    /** @var array<array-key, true> $strings */
    $strings = [];
    /** @var array<array-key, true> $others */
    $others = [];
    /** @var list<Tv> $result */
    $result = [];
    foreach ($iterable as $value) {
        if (is_string($value)) {
            if (isset($strings[$value])) {
                continue;
            }

            $strings[$value] = true;
        } elseif (is_int($value)) {
            if (isset($ints[$value])) {
                continue;
            }

            $ints[$value] = true;
        } else {
            // Floats are keyed by their exact 8-byte representation (`-0.0` shares the key of `0.0`, as
            // `-0.0 === 0.0`), `NAN` by a single 3-byte key, and booleans by 4/5-byte keys, so none of
            // them can collide.
            if (is_float($value)) {
                $float = $value;
                $key = match (true) {
                    is_nan($float) => 'NAN',
                    0.0 === $float => pack('E', 0.0),
                    default => pack('E', $float),
                };
            } else {
                $key = $value ? 'true' : 'false';
            }

            if (isset($others[$key])) {
                continue;
            }

            $others[$key] = true;
        }

        $result[] = $value;
    }

    return $result;
}
