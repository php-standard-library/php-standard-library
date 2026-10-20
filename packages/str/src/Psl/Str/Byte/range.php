<?php

declare(strict_types=1);

namespace Psl\Str\Byte;

use Psl\Range\LowerBoundRangeInterface;
use Psl\Range\RangeInterface;
use Psl\Range\UpperBoundRangeInterface;
use Psl\Str\Exception;

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
 * use Psl\Str\Byte;
 *
 * $string = 'Hello, World!';
 *
 * Byte\range($string, Range\between(0, 3, upperInclusive: true)); // 'Hell'
 * Byte\range($string, Range\between(0, 3, upperInclusive: false)); // 'Hel'
 * Byte\range($string, Range\from(3)); // 'lo, World!'
 * Byte\range($string, Range\to(3, true)); // 'Hell'
 * Byte\range($string, Range\to(3, false)); // 'Hel'
 * Byte\range($string, Range\full()); // 'Hello, World!'
 * Byte\range($string, Range\between(7, 11, true)); // 'World'
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
function range(string $string, RangeInterface $range): string
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

    return namespace\slice($string, $offset, $length);
}
