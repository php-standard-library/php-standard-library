<?php

declare(strict_types=1);

namespace Psl\Str;

use Psl\Range\LowerBoundRangeInterface;
use Psl\Range\RangeInterface;
use Psl\Range\UpperBoundRangeInterface;

/**
 * Slice a string using a range.
 *
 * If the range doesn't have an upper range, the slice will contain the
 * rest of the string. If the upper-bound is exclusive and equal to the
 * lower-bound, then an empty string will be returned.
 *
 * Example:
 *
 * ```php
 * use Psl\Range;
 * use Psl\Str;
 *
 * $string = 'Hello, World!';
 *
 * Str\range($string, Range\between(0, 3, upperInclusive: true)); // 'Hell'
 * Str\range($string, Range\between(0, 3, upperInclusive: false)); // 'Hel'
 * Str\range($string, Range\from(3)); // 'lo, World!'
 * Str\range($string, Range\to(3, true)); // 'Hell'
 * Str\range($string, Range\to(3, false)); // 'Hel'
 * Str\range($string, Range\full()); // 'Hello, World!'
 * Str\range($string, Range\between(7, 11, true)); // 'World'
 * ```
 *
 * @param RangeInterface $range
 *
 * @throws Exception\OutOfBoundsException If the $offset is out-of-bounds.
 *
 * @pure
 *
 * @api
 */
function range(string $string, RangeInterface $range, Encoding $encoding = Encoding::Utf8): string
{
    $offset = 0;
    $length = null;
    if ($range instanceof LowerBoundRangeInterface) {
        $offset = $range->getLowerBound();
    }

    if ($range instanceof UpperBoundRangeInterface) {
        $length = $range->getUpperBound() - $offset;
        if ($range->isUpperInclusive()) {
            $length += 1;
        }

        // @codeCoverageIgnoreStart
        if ($length < 0) {
            $length = 0;
        }
        // @codeCoverageIgnoreEnd
    }

    return namespace\slice($string, $offset, $length, $encoding);
}
