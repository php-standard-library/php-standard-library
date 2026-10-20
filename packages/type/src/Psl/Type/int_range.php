<?php

declare(strict_types=1);

namespace Psl\Type;

use Psl\Exception\InvariantViolationException;

/**
 * Returns a type that matches integers between $min and $max, both inclusive.
 *
 * @throws InvariantViolationException If $min is greater than $max.
 *
 * @return TypeInterface<int>
 *
 * @api
 */
function int_range(int $min, int $max): TypeInterface
{
    return new Internal\IntRangeType($min, $max);
}
