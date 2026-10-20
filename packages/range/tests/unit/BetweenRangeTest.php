<?php

declare(strict_types=1);

namespace Psl\Range\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Iter;
use Psl\Math;
use Psl\Range;

final class BetweenRangeTest extends TestCase
{
    public function testContains(): void
    {
        $range = Range\between(Math\INT16_MIN, Math\INT16_MAX);

        static::assertTrue($range->contains(100));
        static::assertTrue($range->contains(99));
        static::assertTrue($range->contains(1));
        static::assertTrue($range->contains(Math\INT8_MIN));
        static::assertTrue($range->contains(Math\INT16_MIN));
        static::assertTrue($range->contains(Math\UINT8_MAX));
        static::assertTrue($range->contains(Math\INT8_MAX));
        static::assertTrue($range->contains(Math\INT16_MAX - 1));
        static::assertFalse($range->contains(Math\INT16_MAX));
        static::assertFalse($range->contains(Math\UINT16_MAX));
        static::assertFalse($range->contains(Math\INT32_MIN));
        static::assertFalse($range->contains(Math\INT53_MIN));
        static::assertFalse($range->contains(Math\INT64_MIN));
        static::assertFalse($range->contains(Math\INT32_MAX));
        static::assertFalse($range->contains(Math\INT53_MAX));
        static::assertFalse($range->contains(Math\INT64_MAX));

        $range = Range\between(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: false);

        static::assertFalse($range->contains(Math\INT16_MAX));
        static::assertTrue($range->contains(Math\INT16_MAX - 1));
        static::assertTrue($range->contains(Math\INT16_MIN));

        $range = Range\between(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: true);

        static::assertTrue($range->contains(Math\INT16_MAX));
        static::assertTrue($range->contains(Math\INT16_MAX - 1));
        static::assertTrue($range->contains(Math\INT16_MIN));

        $range = new Range\BetweenRange(Math\INT16_MIN, Math\INT16_MAX);

        static::assertTrue($range->contains(100));
        static::assertTrue($range->contains(99));
        static::assertTrue($range->contains(1));
        static::assertTrue($range->contains(Math\INT8_MIN));
        static::assertTrue($range->contains(Math\INT16_MIN));
        static::assertTrue($range->contains(Math\UINT8_MAX));
        static::assertTrue($range->contains(Math\INT8_MAX));
        static::assertTrue($range->contains(Math\INT16_MAX - 1));
        static::assertFalse($range->contains(Math\INT16_MAX));
        static::assertFalse($range->contains(Math\UINT16_MAX));
        static::assertFalse($range->contains(Math\INT32_MIN));
        static::assertFalse($range->contains(Math\INT53_MIN));
        static::assertFalse($range->contains(Math\INT64_MIN));
        static::assertFalse($range->contains(Math\INT32_MAX));
        static::assertFalse($range->contains(Math\INT53_MAX));
        static::assertFalse($range->contains(Math\INT64_MAX));

        $range = new Range\BetweenRange(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: true);

        static::assertTrue($range->contains(Math\INT16_MAX));
        static::assertTrue($range->contains(Math\INT16_MIN));
        static::assertTrue($range->contains(Math\INT16_MIN + 1));
    }

    public function testBounds(): void
    {
        $range = Range\between(Math\INT16_MIN, Math\INT16_MAX);
        static::assertSame(Math\INT16_MIN, $range->getLowerBound());
        static::assertSame(Math\INT16_MAX, $range->getUpperBound());

        $range = Range\between(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: false);
        static::assertSame(Math\INT16_MIN, $range->getLowerBound());
        static::assertSame(Math\INT16_MAX, $range->getUpperBound());

        $range = Range\between(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: true);
        static::assertSame(Math\INT16_MIN, $range->getLowerBound());
        static::assertSame(Math\INT16_MAX, $range->getUpperBound());

        $range = new Range\BetweenRange(Math\INT16_MIN, Math\INT16_MAX);
        static::assertSame(Math\INT16_MIN, $range->getLowerBound());
        static::assertSame(Math\INT16_MAX, $range->getUpperBound());

        $range = new Range\BetweenRange(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: false);
        static::assertSame(Math\INT16_MIN, $range->getLowerBound());
        static::assertSame(Math\INT16_MAX, $range->getUpperBound());

        $range = new Range\BetweenRange(Math\INT16_MIN, Math\INT16_MAX, upperInclusive: true);
        static::assertSame(Math\INT16_MIN, $range->getLowerBound());
        static::assertSame(Math\INT16_MAX, $range->getUpperBound());
    }

    public function testIsInclusive(): void
    {
        $range = Range\between(0, 100, upperInclusive: true);
        static::assertTrue($range->isUpperInclusive());

        $range = Range\between(0, 100, upperInclusive: false);
        static::assertFalse($range->isUpperInclusive());

        $range = Range\between(0, 100);
        static::assertFalse($range->isUpperInclusive());

        $range = new Range\BetweenRange(0, 100, upperInclusive: true);
        static::assertTrue($range->isUpperInclusive());

        $range = new Range\BetweenRange(0, 100, upperInclusive: false);
        static::assertFalse($range->isUpperInclusive());

        $range = new Range\BetweenRange(0, 100, upperInclusive: true);
        static::assertTrue($range->isUpperInclusive());

        $range = new Range\BetweenRange(0, 100);
        static::assertFalse($range->isUpperInclusive());
    }

    public function testWithers(): void
    {
        $range = new Range\BetweenRange(0, 100);

        static::assertSame(0, $range->getLowerBound());
        static::assertSame(100, $range->getUpperBound());
        static::assertSame(0, $range->withLowerBound(0)->getLowerBound());
        static::assertSame(1, $range->withUpperBound(1, false)->getUpperBound());
        static::assertSame(1, $range->withUpperBoundExclusive(1)->getUpperBound());
        static::assertSame(1, $range->withUpperBoundInclusive(1)->getUpperBound());
        static::assertFalse($range->withUpperBound(0, false)->isUpperInclusive());
        static::assertFalse($range->withUpperInclusive(false)->isUpperInclusive());
        static::assertTrue($range->withUpperInclusive(true)->isUpperInclusive());
        static::assertFalse($range->withUpperBound(0, false)->isUpperInclusive());
        static::assertTrue($range->withUpperBound(0, true)->isUpperInclusive());
        static::assertFalse($range->withUpperBoundExclusive(0)->isUpperInclusive());
        static::assertTrue($range->withUpperBoundInclusive(0)->isUpperInclusive());

        static::assertSame(0, $range->withoutUpperBound()->withLowerBound(0)->getLowerBound());
        static::assertSame(1, $range->withoutUpperBound()->withUpperBound(1, false)->getUpperBound());
        static::assertSame(1, $range->withoutUpperBound()->withUpperBoundExclusive(1)->getUpperBound());
        static::assertSame(1, $range->withoutUpperBound()->withUpperBoundInclusive(1)->getUpperBound());
        static::assertFalse($range->withoutUpperBound()->withUpperBound(0, false)->isUpperInclusive());
        static::assertFalse($range->withoutUpperBound()->withUpperBound(0, false)->isUpperInclusive());
        static::assertTrue($range->withoutUpperBound()->withUpperBound(0, true)->isUpperInclusive());
        static::assertFalse($range->withoutUpperBound()->withUpperBoundExclusive(0)->isUpperInclusive());
        static::assertTrue($range->withoutUpperBound()->withUpperBoundInclusive(0)->isUpperInclusive());

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
        $range = Range\between(1, 10);
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9];
        $actual = [];
        foreach ($range as $value) {
            $actual[] = $value;
        }

        static::assertSame($expected, $actual);

        $range = Range\between(1, 10, upperInclusive: true);
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $actual = [];
        foreach ($range as $value) {
            $actual[] = $value;
        }

        static::assertSame($expected, $actual);

        $range = new Range\BetweenRange(1, 10);
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9];
        $actual = [];
        foreach ($range as $value) {
            $actual[] = $value;
        }

        static::assertSame($expected, $actual);

        $range = new Range\BetweenRange(1, 10, upperInclusive: true);
        $expected = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $actual = [];
        foreach ($range as $value) {
            $actual[] = $value;
        }

        static::assertSame($expected, $actual);
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('provideIterateBoundaries')]
    public function testIterateBoundaries(array $expected, int $lower, int $upper, bool $upperInclusive): void
    {
        $actual = [];
        foreach (Range\between($lower, $upper, $upperInclusive) as $value) {
            $actual[] = $value;

            // guard against an infinite loop.
            if (Iter\count($actual) > 10) {
                break;
            }
        }

        static::assertSame($expected, $actual);
    }

    public static function provideIterateBoundaries(): iterable
    {
        yield 'empty exclusive range' => [[], 5, 5, false];
        yield 'single value inclusive range' => [[5], 5, 5, true];
        yield 'exclusive up to INT64_MAX' => [
            [Math\INT64_MAX - 2, Math\INT64_MAX - 1],
            Math\INT64_MAX - 2,
            Math\INT64_MAX,
            false,
        ];
        yield 'inclusive up to INT64_MAX' => [
            [Math\INT64_MAX - 2, Math\INT64_MAX - 1, Math\INT64_MAX],
            Math\INT64_MAX - 2,
            Math\INT64_MAX,
            true,
        ];
        yield 'only INT64_MAX inclusive' => [[Math\INT64_MAX], Math\INT64_MAX, Math\INT64_MAX, true];
        yield 'only INT64_MAX exclusive' => [[], Math\INT64_MAX, Math\INT64_MAX, false];
        yield 'only INT64_MIN inclusive' => [[Math\INT64_MIN], Math\INT64_MIN, Math\INT64_MIN, true];
        yield 'only INT64_MIN exclusive' => [[], Math\INT64_MIN, Math\INT64_MIN, false];
        yield 'exclusive from INT64_MIN' => [
            [Math\INT64_MIN, Math\INT64_MIN + 1],
            Math\INT64_MIN,
            Math\INT64_MIN + 2,
            false,
        ];
    }

    public function testInvalidRange(): void
    {
        $this->expectException(Range\Exception\InvalidRangeException::class);
        $this->expectExceptionMessage('`$lowerBound` (10) must be less than or equal to `$upperBound` (5).');

        try {
            Range\between(10, 5);
        } catch (Range\Exception\InvalidRangeException $e) {
            static::assertSame(10, $e->getLowerBound());
            static::assertSame(5, $e->getUpperBound());

            throw $e;
        }
    }
}
