<?php

declare(strict_types=1);

namespace Psl\Range;

use Generator;
use Override;
use Psl\Iter;

/**
 * A `BetweenRange` is a range that contains all values between the given lower and upper bound.
 *
 * This range can serve as an Iterator, starting from the lower bound, and ending at the upper bound.
 *
 * Example:
 *
 * ```php
 * use Psl\Range;
 *
 * $range = new Range\BetweenRange(1, 10, inclusive: false);
 *
 * foreach ($range as $value) {
 *      // $value will be 1, 2, 3, 4, 5, 6, 7, 8, 9
 * }
 *
 * $range = new Range\BetweenRange(1, 10, inclusive: true);
 *
 * foreach ($range as $value) {
 *     // $value will be 1, 2, 3, 4, 5, 6, 7, 8, 9, 10
 * }
 * ```
 *
 * @see RangeInterface::contains()
 * @see RangeInterface::withLowerBound()
 * @see RangeInterface::withUpperBound()
 * @see LowerBoundRangeInterface::getLowerBound()
 * @see UpperBoundRangeInterface::getUpperBound()
 * @see UpperBoundRangeInterface::withUpperInclusive()
 * @see UpperBoundRangeInterface::isUpperInclusive()
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class BetweenRange implements LowerBoundRangeInterface, UpperBoundRangeInterface
{
    private int $lowerBound;
    private int $upperBound;
    private bool $upperInclusive;

    /**
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    public function __construct(int $lowerBound, int $upperBound, bool $upperInclusive = false)
    {
        if ($lowerBound > $upperBound) {
            throw Exception\InvalidRangeException::lowerBoundIsGreaterThanUpperBound($lowerBound, $upperBound);
        }

        $this->lowerBound = $lowerBound;
        $this->upperBound = $upperBound;
        $this->upperInclusive = $upperInclusive;
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function contains(int $value): bool
    {
        if ($value < $this->lowerBound) {
            return false;
        }

        if ($this->upperInclusive) {
            return $value <= $this->upperBound;
        }

        return $value < $this->upperBound;
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperBound(int $upperBound, bool $upperInclusive): BetweenRange
    {
        return new BetweenRange($this->lowerBound, $upperBound, $upperInclusive);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperBoundInclusive(int $upperBound): BetweenRange
    {
        return new BetweenRange($this->lowerBound, $upperBound, true);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperBoundExclusive(int $upperBound): BetweenRange
    {
        return new BetweenRange($this->lowerBound, $upperBound, false);
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withoutLowerBound(): ToRange
    {
        return new ToRange($this->upperBound, $this->upperInclusive);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidRangeException If the lower bound is greater than the upper bound.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withLowerBound(int $lowerBound): BetweenRange
    {
        return new static($lowerBound, $this->upperBound, $this->upperInclusive);
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withoutUpperBound(): FromRange
    {
        return new FromRange($this->lowerBound);
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getUpperBound(): int
    {
        return $this->upperBound;
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function isUpperInclusive(): bool
    {
        return $this->upperInclusive;
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withUpperInclusive(bool $upperInclusive): static
    {
        return new static($this->lowerBound, $this->upperBound, $upperInclusive);
    }

    /**
     * {@inheritDoc}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getLowerBound(): int
    {
        return $this->lowerBound;
    }

    /**
     * {@inheritDoc}
     *
     * @return Iter\Iterator<int, int>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getIterator(): Iter\Iterator
    {
        $lower = $this->lowerBound;
        $upper = $this->upperBound;
        $inclusive = $this->upperInclusive;

        return Iter\Iterator::from(
            /**
             * @return Generator<int, int, mixed, void>
             */
            static function () use ($lower, $upper, $inclusive): Generator {
                if (!$inclusive) {
                    if ($lower === $upper) {
                        return;
                    }

                    $upper -= 1;
                }

                // Stop before incrementing past the last value, as incrementing `PHP_INT_MAX` yields a float.
                for ($i = $lower; $i < $upper; $i++) {
                    yield $i;
                }

                yield $upper;
            },
        );
    }
}
