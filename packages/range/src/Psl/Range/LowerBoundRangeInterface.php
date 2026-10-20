<?php

declare(strict_types=1);

namespace Psl\Range;

use IteratorAggregate;
use Override;
use Psl\Iter;

/**
 * @extends IteratorAggregate<int, int>
 *
 * @psalm-immutable
 *
 * @api
 */
interface LowerBoundRangeInterface extends IteratorAggregate, RangeInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperBound(
        int $upperBound,
        bool $upperInclusive,
    ): UpperBoundRangeInterface&LowerBoundRangeInterface;

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperBoundInclusive(int $upperBound): UpperBoundRangeInterface&LowerBoundRangeInterface;

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperBoundExclusive(int $upperBound): UpperBoundRangeInterface&LowerBoundRangeInterface;

    /**
     * Remove the lower bound from the range.
     *
     * @psalm-mutation-free
     */
    public function withoutLowerBound(): RangeInterface;

    /**
     * Returns the lower bound of the range.
     *
     * @psalm-mutation-free
     */
    public function getLowerBound(): int;

    /**
     * Returns an iterator for the range.
     *
     * If this range has no upper bound, the iterator will be infinite: once `PHP_INT_MAX` has been
     * yielded, advancing the iterator throws {@see Exception\OverflowException}.
     *
     * If this range has an upper bound, the iterator stops after the last value of the range,
     * even if that value is `PHP_INT_MAX`.
     *
     * @return Iter\Iterator<int, int>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getIterator(): Iter\Iterator;
}
