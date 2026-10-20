<?php

declare(strict_types=1);

namespace Psl\Range\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Math;
use Psl\Range;

final class FromRangeTest extends TestCase
{
    public function testContains(): void
    {
        $range = Range\from(100);

        static::assertTrue($range->contains(100));
        static::assertTrue($range->contains(Math\INT8_MAX));
        static::assertTrue($range->contains(Math\INT16_MAX));
        static::assertTrue($range->contains(Math\INT32_MAX));
        static::assertTrue($range->contains(Math\INT53_MAX));
        static::assertTrue($range->contains(Math\INT64_MAX));
        static::assertTrue($range->contains(Math\UINT8_MAX));
        static::assertTrue($range->contains(Math\UINT16_MAX));
        static::assertTrue($range->contains(Math\UINT32_MAX));

        static::assertFalse($range->contains(99));
        static::assertFalse($range->contains(1));
        static::assertFalse($range->contains(Math\INT8_MIN));
        static::assertFalse($range->contains(Math\INT16_MIN));
        static::assertFalse($range->contains(Math\INT32_MIN));
        static::assertFalse($range->contains(Math\INT53_MIN));
        static::assertFalse($range->contains(Math\INT64_MIN));
    }

    public function testLowerBound(): void
    {
        $range = Range\from(10);
        static::assertSame(10, $range->getLowerBound());

        $range = Range\from(2);
        static::assertSame(2, $range->getLowerBound());
    }

    public function testWithers(): void
    {
        $range = new Range\FromRange(0);

        static::assertSame(0, $range->getLowerBound());
        static::assertSame(0, $range->withLowerBound(0)->getLowerBound());
        static::assertSame(1, $range->withUpperBound(1, false)->getUpperBound());
        static::assertSame(1, $range->withUpperBoundExclusive(1)->getUpperBound());
        static::assertSame(1, $range->withUpperBoundInclusive(1)->getUpperBound());
        static::assertFalse($range->withUpperBound(0, false)->isUpperInclusive());
        static::assertFalse($range->withUpperBound(0, true)->withUpperInclusive(false)->isUpperInclusive());
        static::assertTrue($range->withUpperBound(0, false)->withUpperInclusive(true)->isUpperInclusive());
        static::assertFalse($range->withUpperBound(0, false)->isUpperInclusive());
        static::assertTrue($range->withUpperBound(0, true)->isUpperInclusive());
        static::assertFalse($range->withUpperBoundExclusive(0)->isUpperInclusive());
        static::assertTrue($range->withUpperBoundInclusive(0)->isUpperInclusive());

        static::assertSame(0, $range->withoutLowerBound()->withLowerBound(0)->getLowerBound());
        static::assertSame(1, $range->withoutLowerBound()->withUpperBound(1, false)->getUpperBound());
        static::assertSame(1, $range->withoutLowerBound()->withUpperBoundExclusive(1)->getUpperBound());
        static::assertSame(1, $range->withoutLowerBound()->withUpperBoundInclusive(1)->getUpperBound());
        static::assertFalse($range->withoutLowerBound()->withUpperBound(0, false)->isUpperInclusive());
        static::assertFalse($range->withoutLowerBound()->withUpperBound(0, false)->isUpperInclusive());
        static::assertTrue($range->withoutLowerBound()->withUpperBound(0, true)->isUpperInclusive());
        static::assertFalse($range->withoutLowerBound()->withUpperBoundExclusive(0)->isUpperInclusive());
        static::assertTrue($range->withoutLowerBound()->withUpperBoundInclusive(0)->isUpperInclusive());
    }

    public function testIterate(): void
    {
        $range = Range\from(10);
        $last = null;
        foreach ($range as $value) {
            if (null !== $last) {
                static::assertSame($last + 1, $value);
            } else {
                static::assertSame(10, $value);
            }

            $last = $value;
            // break after the 3rd iteration, otherwise we will be here forever.
            if (13 === $value) {
                break;
            }
        }
    }

    public function testOverflow(): void
    {
        $range = Range\from(Math\INT64_MAX);

        $this->expectException(Range\Exception\OverflowException::class);
        $this->expectExceptionMessage('f');

        foreach ($range as $_) {
            // @mago-expect lint:no-empty-loop
        }
    }

    public function testOverflowHappensAfterYieldingIntMax(): void
    {
        $values = [];
        try {
            foreach (Range\from(Math\INT64_MAX - 1) as $value) {
                $values[] = $value;
            }

            static::fail('Expected an overflow exception.');
        } catch (Range\Exception\OverflowException $e) {
            static::assertSame(
                'An overflow occurred while iterating over an infinite range from the `$lowerBound` of '
                . (Math\INT64_MAX - 1)
                . '.',
                $e->getMessage(),
            );
        }

        static::assertSame([Math\INT64_MAX - 1, Math\INT64_MAX], $values);
    }
}
