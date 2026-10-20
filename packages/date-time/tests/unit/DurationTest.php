<?php

declare(strict_types=1);

namespace Psl\DateTime\Tests\Unit;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Comparison\Order;
use Psl\DateTime;
use Psl\Json;
use Throwable;

use function intdiv;
use function serialize;
use function unserialize;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class DurationTest extends TestCase
{
    use DateTimeTestTrait;

    public function testGetters(): void
    {
        $t = DateTime\Duration::fromParts(1, 2, 3, 4);

        static::assertSame(1, $t->getHours());
        static::assertSame(2, $t->getMinutes());
        static::assertSame(3, $t->getSeconds());
        static::assertSame(4, $t->getNanoseconds());
        static::assertSame([1, 2, 3, 4], $t->getParts());
    }

    public function testNamedConstructors(): void
    {
        static::assertSame(1.0, DateTime\Duration::hours(1)->getTotalHours());
        static::assertSame(1.0, DateTime\Duration::minutes(1)->getTotalMinutes());
        static::assertSame(1.0, DateTime\Duration::seconds(1)->getTotalSeconds());
        static::assertSame(1.0, DateTime\Duration::milliseconds(1)->getTotalMilliseconds());
        static::assertSame(1.0, DateTime\Duration::microseconds(1)->getTotalMicroseconds());
        static::assertSame(1, DateTime\Duration::nanoseconds(1)->getNanoseconds());
        static::assertSame(0.0, DateTime\Duration::zero()->getTotalSeconds());
    }

    public static function provideGetTotalHours(): array
    {
        return [
            [0, 0, 0, 0, 0.0],
            [0, 0, 0, 1, 2.777_777_777_777_778E-13],
            [1, 0, 0, 0, 1.0],
            [1, 30, 0, 0, 1.5],
            [2, 15, 30, 0, 2.258_333_333_333_333_3],
            [-1, 0, 0, 0, -1.0],
            [-1, -30, 0, 0, -1.5],
            [-2, -15, -30, 0, -2.258_333_333_333_333_3],
        ];
    }

    #[DataProvider('provideGetTotalHours')]
    public function testGetTotalHours(
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
        float $expectedHours,
    ): void {
        $time = DateTime\Duration::fromParts($hours, $minutes, $seconds, $nanoseconds);
        static::assertSame($expectedHours, $time->getTotalHours());
    }

    public static function provideGetTotalMinutes(): array
    {
        return [
            [0, 0, 0, 0, 0.0],
            [0, 0, 0, 1, 1.666_666_666_666_666_7E-11],
            [1, 0, 0, 0, 60.0],
            [1, 30, 0, 0, 90.0],
            [2, 15, 30, 0, 135.5],
            [-1, 0, 0, 0, -60.0],
            [-1, -30, 0, 0, -90.0],
            [-2, -15, -30, 0, -135.5],
        ];
    }

    #[DataProvider('provideGetTotalMinutes')]
    public function testGetTotalMinutes(
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
        float $expectedMinutes,
    ): void {
        $time = DateTime\Duration::fromParts($hours, $minutes, $seconds, $nanoseconds);
        static::assertSame($expectedMinutes, $time->getTotalMinutes());
    }

    public static function provideGetTotalSeconds(): array
    {
        return [
            [0, 0, 0, 0, 0.0],
            [0, 0, 0, 1, 0.000_000_001],
            [1, 0, 0, 0, 3_600.0],
            [1, 30, 0, 0, 5_400.0],
            [2, 15, 30, 0, 8_130.0],
            [-1, 0, 0, 0, -3_600.0],
            [-1, -30, 0, 0, -5_400.0],
            [-2, -15, -30, 0, -8_130.0],
        ];
    }

    #[DataProvider('provideGetTotalSeconds')]
    public function testGetTotalSeconds(
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
        float $expectedSeconds,
    ): void {
        $time = DateTime\Duration::fromParts($hours, $minutes, $seconds, $nanoseconds);
        static::assertSame($expectedSeconds, $time->getTotalSeconds());
    }

    public static function provideGetTotalMilliseconds(): array
    {
        return [
            [0, 0, 0, 0, 0.0],
            [0, 0, 0, 1, 0.000_001],
            [1, 0, 0, 0, 3_600_000.0],
            [1, 30, 0, 0, 5_400_000.0],
            [2, 15, 30, 0, 8_130_000.0],
            [-1, 0, 0, 0, -3_600_000.0],
            [-1, -30, 0, 0, -5_400_000.0],
            [-2, -15, -30, 0, -8_130_000.0],
        ];
    }

    #[DataProvider('provideGetTotalMilliseconds')]
    public function testGetTotalMilliseconds(
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
        float $expectedMilliseconds,
    ): void {
        $time = DateTime\Duration::fromParts($hours, $minutes, $seconds, $nanoseconds);
        static::assertSame($expectedMilliseconds, $time->getTotalMilliseconds());
    }

    public static function provideGetTotalMicroseconds(): array
    {
        return [
            [0, 0, 0, 0, 0.0],
            [0, 0, 0, 1, 0.001],
            [1, 0, 0, 0, 3_600_000_000.0],
            [1, 30, 0, 0, 5_400_000_000.0],
            [2, 15, 30, 0, 8_130_000_000.0],
            [-1, 0, 0, 0, -3_600_000_000.0],
            [-1, -30, 0, 0, -5_400_000_000.0],
            [-2, -15, -30, 0, -8_130_000_000.0],
        ];
    }

    #[DataProvider('provideGetTotalMicroseconds')]
    public function testGetTotalMicroseconds(
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
        float $expectedMicroseconds,
    ): void {
        $time = DateTime\Duration::fromParts($hours, $minutes, $seconds, $nanoseconds);
        static::assertSame($expectedMicroseconds, $time->getTotalMicroseconds());
    }

    public function testSetters(): void
    {
        $t = DateTime\Duration::fromParts(1, 2, 3, 4);

        static::assertSame([42, 2, 3, 4], $t->withHours(42)->getParts());
        static::assertSame([1, 42, 3, 4], $t->withMinutes(42)->getParts());
        static::assertSame([1, 2, 42, 4], $t->withSeconds(42)->getParts());
        static::assertSame([1, 2, 3, 42], $t->withNanoseconds(42)->getParts());
        static::assertSame([2, 3, 3, 4], $t->withMinutes(63)->getParts());
        static::assertSame([1, 3, 3, 4], $t->withSeconds(63)->getParts());
        static::assertSame([1, 2, 4, 42], $t->withNanoseconds(DateTime\NANOSECONDS_PER_SECOND + 42)->getParts());
        static::assertSame([1, 2, 3, 4], $t->getParts());
    }

    public function testFractionsOfSecond(): void
    {
        static::assertSame([0, 0, 0, 0], DateTime\Duration::zero()->getParts());
        static::assertSame([0, 0, 0, 42], DateTime\Duration::nanoseconds(42)->getParts());
        static::assertSame(
            [0, 0, 1, 42],
            DateTime\Duration::nanoseconds(DateTime\NANOSECONDS_PER_SECOND + 42)->getParts(),
        );
        static::assertSame([0, 0, 0, 42_000], DateTime\Duration::microseconds(42)->getParts());
        static::assertSame([0, 0, 1, 42_000], DateTime\Duration::microseconds(1_000_042)->getParts());
        static::assertSame([0, 0, 0, 42_000_000], DateTime\Duration::milliseconds(42)->getParts());
        static::assertSame([0, 0, 1, 42_000_000], DateTime\Duration::milliseconds(1042)->getParts());
    }

    /**
     * @return list<array{int, int, int, int}>
     */
    public static function provideNormalized(): array
    {
        return [
            // input seconds, input ns, normalized seconds, normalized ns
            [0, 0, 0, 0],
            [0, 3, 0, 3],
            [3, 0, 3, 0],
            [1, 3, 1, 3],
            [1, -3, 0, DateTime\NANOSECONDS_PER_SECOND - 3],
            [-1, 3, 0, -(DateTime\NANOSECONDS_PER_SECOND - 3)],
            [-1, -3, -1, -3],
            [1, DateTime\NANOSECONDS_PER_SECOND + 42, 2, 42],
            [1, -(DateTime\NANOSECONDS_PER_SECOND + 42), 0, -42],
            [2, -3, 1, DateTime\NANOSECONDS_PER_SECOND - 3],
        ];
    }

    #[DataProvider('provideNormalized')]
    public function testNormalized(int $inputS, int $inputNs, int $normalizedS, int $normalizedNs): void
    {
        static::assertSame(
            [0, 0, $normalizedS, $normalizedNs],
            DateTime\Duration::fromParts(0, 0, $inputS, $inputNs)->getParts(),
        );
    }

    public function testNormalizedHMS(): void
    {
        static::assertSame([3, 5, 4, 0], DateTime\Duration::fromParts(2, 63, 124)->getParts());
        static::assertSame([0, 59, 4, 0], DateTime\Duration::fromParts(2, -63, 124)->getParts());
        static::assertSame(
            [-1, 0, -55, -(DateTime\NANOSECONDS_PER_SECOND - 42)],
            DateTime\Duration::fromParts(0, -63, 124, 42)->getParts(),
        );
        static::assertSame([42, 0, 0, 0], DateTime\Duration::hours(42)->getParts());
        static::assertSame([1, 3, 0, 0], DateTime\Duration::minutes(63)->getParts());
        static::assertSame([0, -1, -3, 0], DateTime\Duration::seconds(-63)->getParts());
        static::assertSame([0, 0, -1, 0], DateTime\Duration::nanoseconds(-DateTime\NANOSECONDS_PER_SECOND)->getParts());
    }

    /**
     * @return list<array{int, int, int, int, int}>
     */
    public static function providePositiveNegative(): array
    {
        return [
            // h, m, s, ns, expected sign
            [0, 0, 0, 0, 0],
            [0, 42, 0, 0, 1],
            [0, 0, -42, 0, -1],
            [1, -63, 0, 0, -1],
        ];
    }

    #[DataProvider('providePositiveNegative')]
    public function testPositiveNegative(int $h, int $m, int $s, int $ns, int $expectedSign): void
    {
        $t = DateTime\Duration::fromParts($h, $m, $s, $ns);
        static::assertSame(0 === $expectedSign, $t->isZero());
        static::assertSame(1 === $expectedSign, $t->isPositive());
        static::assertSame($expectedSign === -1, $t->isNegative());
    }

    /**
     * @return list<array{DateTime\Duration, DateTime\Duration, Order}>
     */
    public static function provideCompare(): array
    {
        return [
            [DateTime\Duration::seconds(20), DateTime\Duration::seconds(10), Order::Greater],
            [DateTime\Duration::seconds(10), DateTime\Duration::seconds(20), Order::Less],
            [DateTime\Duration::seconds(10), DateTime\Duration::seconds(10), Order::Equal],
            [DateTime\Duration::hours(1), DateTime\Duration::minutes(42), Order::Greater],
            [DateTime\Duration::minutes(2), DateTime\Duration::seconds(120), Order::Equal],
            [DateTime\Duration::zero(), DateTime\Duration::nanoseconds(1), Order::Less],
        ];
    }

    #[DataProvider('provideCompare')]
    public function testCompare(DateTime\Duration $a, DateTime\Duration $b, Order $expected): void
    {
        $opposite = Order::from(-$expected->value);

        static::assertSame($expected, $a->compare($b));
        static::assertSame($opposite, $b->compare($a));
        static::assertSame($expected === Order::Equal, $a->equals($b));
        static::assertSame($expected === Order::Less, $a->shorter($b));
        static::assertSame($expected !== Order::Greater, $a->shorterOrEqual($b));
        static::assertSame($expected === Order::Greater, $a->longer($b));
        static::assertSame($expected !== Order::Less, $a->longerOrEqual($b));
        static::assertFalse($a->betweenExclusive($a, $a));
        static::assertFalse($a->betweenExclusive($a, $b));
        static::assertFalse($a->betweenExclusive($b, $a));
        static::assertFalse($a->betweenExclusive($b, $b));
        static::assertTrue($a->betweenInclusive($a, $a));
        static::assertTrue($a->betweenInclusive($a, $b));
        static::assertTrue($a->betweenInclusive($b, $a));
        static::assertSame($expected === Order::Equal, $a->betweenInclusive($b, $b));
    }

    public function testIsBetween(): void
    {
        $a = DateTime\Duration::hours(1);
        $b = DateTime\Duration::minutes(64);
        $c = DateTime\Duration::fromParts(1, 30);
        static::assertTrue($b->betweenExclusive($a, $c));
        static::assertTrue($b->betweenExclusive($c, $a));
        static::assertTrue($b->betweenInclusive($a, $c));
        static::assertTrue($b->betweenInclusive($c, $a));
        static::assertFalse($a->betweenExclusive($b, $c));
        static::assertFalse($a->betweenInclusive($c, $b));
        static::assertFalse($c->betweenInclusive($a, $b));
        static::assertFalse($c->betweenExclusive($b, $a));
    }

    public function testOperations(): void
    {
        $z = DateTime\Duration::zero();
        $a = DateTime\Duration::fromParts(0, 2, 25);
        $b = DateTime\Duration::fromParts(0, 0, -63, 42);
        static::assertSame([0, 0, 0, 0], $z->invert()->getParts());
        static::assertSame([0, -2, -25, 0], $a->invert()->getParts());
        static::assertSame([0, 1, 2, DateTime\NANOSECONDS_PER_SECOND - 42], $b->invert()->getParts());
        static::assertSame($a->getParts(), $z->plus($a)->getParts());
        static::assertSame($b->getParts(), $b->plus($z)->getParts());
        static::assertSame($b->invert()->getParts(), $z->minus($b)->getParts());
        static::assertSame($a->getParts(), $a->minus($z)->getParts());
        static::assertSame([0, 1, 22, 42], $a->plus($b)->getParts());
        static::assertSame([0, 1, 22, 42], $b->plus($a)->getParts());
        static::assertSame([0, 3, 27, DateTime\NANOSECONDS_PER_SECOND - 42], $a->minus($b)->getParts());
        static::assertSame([0, -3, -27, -(DateTime\NANOSECONDS_PER_SECOND - 42)], $b->minus($a)->getParts());
        static::assertSame($b->invert()->plus($a)->getParts(), $a->minus($b)->getParts());
    }

    /**
     * @return list<array{int, int, int, int, string}>
     */
    public static function provideToString(): array
    {
        return [
            // h, m, s, ns, expected output
            [42, 0, 0, 0, '42 hour(s)'],
            [0, 42, 0, 0, '42 minute(s)'],
            [0, 0, 42, 0, '42 second(s)'],
            [0, 0, 0, 0, '0 second(s)'],
            [0, 0, 0, 42, '0 second(s)'], // rounded because default $maxDecimals = 3
            [0, 0, 1, 42, '1 second(s)'],
            [0, 0, 1, 20_000_000, '1.02 second(s)'],
            [1, 2, 0, 0, '1 hour(s), 2 minute(s)'],
            [1, 0, 3, 0, '1 hour(s), 0 minute(s), 3 second(s)'],
            [0, 2, 3, 0, '2 minute(s), 3 second(s)'],
            [1, 2, 3, 0, '1 hour(s), 2 minute(s), 3 second(s)'],
            [1, 0, 0, 42_000_000, '1 hour(s), 0 minute(s), 0.042 second(s)'],
            [-42, 0, -42, 0, '-42 hour(s), 0 minute(s), -42 second(s)'],
            [-42, 0, -42, -420_000_000, '-42 hour(s), 0 minute(s), -42.42 second(s)'],
            [0, 0, 0, -420_000_000, '-0.42 second(s)'],
        ];
    }

    #[DataProvider('provideToString')]
    public function testToString(int $h, int $m, int $s, int $ns, string $expected): void
    {
        static::assertSame($expected, DateTime\Duration::fromParts($h, $m, $s, $ns)->toString());
    }

    public function testSerialization(): void
    {
        $timeInterval = DateTime\Duration::fromParts(1, 30, 45, 500_000_000);
        $serialized = serialize($timeInterval);
        $deserialized = unserialize($serialized);

        static::assertEquals($timeInterval, $deserialized);
    }

    public function testJsonEncoding(): void
    {
        $timeInterval = DateTime\Duration::fromParts(1, 30, 45, 500_000_000);
        $jsonEncoded = Json\encode($timeInterval);
        $jsonDecoded = Json\decode($jsonEncoded);

        static::assertSame(
            ['hours' => 1, 'minutes' => 30, 'seconds' => 45, 'nanoseconds' => 500_000_000],
            $jsonDecoded,
        );
    }

    public function testToStdlibPositive(): void
    {
        $duration = DateTime\Duration::fromParts(1, 30, 45);

        $interval = $duration->toStdlib();

        static::assertInstanceOf(DateInterval::class, $interval);
        static::assertSame(0, $interval->invert);
        // 1h30m45s = 5445 seconds
        static::assertSame(5445, (int) $interval->s);
    }

    public function testToStdlibNegative(): void
    {
        $duration = DateTime\Duration::fromParts(-2, -15);

        $interval = $duration->toStdlib();

        static::assertInstanceOf(DateInterval::class, $interval);
        // -2h15m = -8100 seconds
        $total = (int) $duration->getTotalSeconds();
        static::assertSame(-8100, $total);
        static::assertSame($total, (int) $interval->s);
    }

    public function testToStdlibZero(): void
    {
        $duration = DateTime\Duration::zero();

        $interval = $duration->toStdlib();

        static::assertInstanceOf(DateInterval::class, $interval);
        static::assertSame(0, (int) $interval->s);
    }

    public function testCompareWithSameHoursDifferentMinutes(): void
    {
        $a = DateTime\Duration::fromParts(2, 10);
        $b = DateTime\Duration::fromParts(2, 20);

        static::assertSame(Order::Less, $a->compare($b));
        static::assertSame(Order::Greater, $b->compare($a));
        static::assertTrue($a->shorter($b));
        static::assertFalse($b->shorter($a));
    }

    public function testInvertZeroReturnsSameInstance(): void
    {
        $zero = DateTime\Duration::zero();

        $inverted = $zero->invert();

        static::assertSame($zero, $inverted);
    }

    public function testPlusZeroReturnsSameInstance(): void
    {
        $d = DateTime\Duration::fromParts(1, 30, 45);
        $zero = DateTime\Duration::zero();

        $result = $d->plus($zero);

        static::assertSame($d, $result);
    }

    public function testZeroPlusOtherReturnsSameInstanceAsOther(): void
    {
        $zero = DateTime\Duration::zero();
        $d = DateTime\Duration::fromParts(1, 30, 45);

        $result = $zero->plus($d);

        static::assertSame($d, $result);
    }

    public function testMinusZeroReturnsSameInstance(): void
    {
        $d = DateTime\Duration::fromParts(1, 30, 45);
        $zero = DateTime\Duration::zero();

        $result = $d->minus($zero);

        static::assertSame($d, $result);
    }

    public function testZeroMinusOtherReturnsInvertedOther(): void
    {
        $zero = DateTime\Duration::zero();
        $d = DateTime\Duration::fromParts(1, 30, 45);

        $result = $zero->minus($d);

        // Should be the inverse of $d
        static::assertSame([-1, -30, -45, 0], $result->getParts());
        static::assertTrue($result->equals($d->invert()));
    }

    public function testPlusWithNonZeroHours(): void
    {
        $a = DateTime\Duration::fromParts(3, 0, 0);
        $b = DateTime\Duration::fromParts(2, 0, 0);

        $result = $a->plus($b);

        static::assertSame(5, $result->getHours());
        static::assertSame(0, $result->getMinutes());
    }

    public function testMinusWithNonZeroHours(): void
    {
        $a = DateTime\Duration::fromParts(5, 0, 0);
        $b = DateTime\Duration::fromParts(2, 0, 0);

        $result = $a->minus($b);

        static::assertSame(3, $result->getHours());
        static::assertSame(0, $result->getMinutes());
    }

    public function testToStringDefaultMaxDecimals(): void
    {
        // With default max_decimals=3, nanoseconds=42 should be rounded to "0 second(s)"
        // because 42 nanoseconds -> "000000042" -> first 3 chars "000" -> trimmed to "" -> "0"
        $d = DateTime\Duration::nanoseconds(42);
        static::assertSame('0 second(s)', $d->toString());

        // With 4 decimals, 42 nanoseconds -> "000000042" -> first 4 chars "0000" -> trimmed to "" -> "0 second(s)"
        // But with 9 decimals, we'd see the full value
        static::assertSame('0.000000042 second(s)', $d->toString(9));

        // 42_000_000 nanoseconds -> "042000000" -> first 3 chars "042" -> "0.042 second(s)"
        $d2 = DateTime\Duration::nanoseconds(42_000_000);
        static::assertSame('0.042 second(s)', $d2->toString());

        // With max_decimals=4: "042000000" -> first 4 chars "0420" -> trimmed to "042" -> "0.042 second(s)"
        // If mutant changes default from 3 to 4, this would still be "0.042" - same result.
        // But with 1234000 nanoseconds: "001234000" -> first 3 chars "001" -> "0.001 second(s)"
        // With 4 decimals: "001234000" -> first 4 chars "0012" -> "0.0012 second(s)"
        $d3 = DateTime\Duration::nanoseconds(1_234_000);
        static::assertSame('0.001 second(s)', $d3->toString());
        static::assertSame('0.0012 second(s)', $d3->toString(4));
    }

    public function testToStringZeroDecimals(): void
    {
        $d = DateTime\Duration::fromParts(1, 30, 45, 500_000_000);

        // With 0 decimals, should not include any decimal part
        $result = $d->toString(0);

        static::assertSame('1 hour(s), 30 minute(s), 45 second(s)', $result);
        static::assertStringNotContainsString('.', $result);
    }

    public function testMagicToStringMatchesToString(): void
    {
        $d = DateTime\Duration::fromParts(1, 30, 45, 500_000_000);

        static::assertSame($d->toString(), (string) $d);
    }

    public function testToStdlibCastInt(): void
    {
        // Duration with nanoseconds that create a fractional second
        $duration = DateTime\Duration::fromParts(0, 0, 5, 999_999_999);

        $interval = $duration->toStdlib();

        // (int) getTotalSeconds() should truncate, not round
        // getTotalSeconds() = 5.999999999, (int) = 5
        static::assertInstanceOf(DateInterval::class, $interval);
        static::assertSame(5, (int) $interval->s);
    }

    public function testCompareWithSameHoursAndMinutesDifferentSeconds(): void
    {
        $a = DateTime\Duration::fromParts(1, 30, 10);
        $b = DateTime\Duration::fromParts(1, 30, 20);

        static::assertSame(Order::Less, $a->compare($b));
        static::assertSame(Order::Greater, $b->compare($a));
    }

    public function testPlusAllComponents(): void
    {
        $a = DateTime\Duration::fromParts(1, 10, 20, 300_000_000);
        $b = DateTime\Duration::fromParts(2, 20, 30, 400_000_000);

        $result = $a->plus($b);

        static::assertSame(3, $result->getHours());
        static::assertSame(30, $result->getMinutes());
        static::assertSame(50, $result->getSeconds());
        static::assertSame(700_000_000, $result->getNanoseconds());
    }

    public function testMinusAllComponents(): void
    {
        $a = DateTime\Duration::fromParts(3, 30, 50, 700_000_000);
        $b = DateTime\Duration::fromParts(1, 10, 20, 300_000_000);

        $result = $a->minus($b);

        static::assertSame(2, $result->getHours());
        static::assertSame(20, $result->getMinutes());
        static::assertSame(30, $result->getSeconds());
        static::assertSame(400_000_000, $result->getNanoseconds());
    }

    /**
     * @return list<array{int, int, int, int, string}>
     */
    public static function provideToIso8601(): array
    {
        return [
            [0, 0, 0, 0, 'PT0S'],
            [5, 0, 0, 0, 'PT5H'],
            [0, 30, 0, 0, 'PT30M'],
            [0, 0, 10, 0, 'PT10S'],
            [5, 30, 0, 0, 'PT5H30M'],
            [5, 30, 10, 0, 'PT5H30M10S'],
            [1, 0, 10, 0, 'PT1H10S'],
            [0, 0, 10, 500_000_000, 'PT10.5S'],
            [0, 0, 0, 123_456_789, 'PT0.123456789S'],
            [0, 0, 0, 100_000_000, 'PT0.1S'],
            [-1, 0, 0, 0, '-PT1H'],
            [-5, -30, -10, 0, '-PT5H30M10S'],
            [0, 0, -10, -500_000_000, '-PT10.5S'],
        ];
    }

    #[DataProvider('provideToIso8601')]
    public function testToIso8601(int $h, int $m, int $s, int $ns, string $expected): void
    {
        static::assertSame($expected, DateTime\Duration::fromParts($h, $m, $s, $ns)->toIso8601());
    }

    /**
     * @return list<array{string, array{int, int, int, int}}>
     */
    public static function provideFromIso8601(): array
    {
        return [
            ['PT0S', [0, 0, 0, 0]],
            ['PT5H', [5, 0, 0, 0]],
            ['PT30M', [0, 30, 0, 0]],
            ['PT10S', [0, 0, 10, 0]],
            ['PT5H30M', [5, 30, 0, 0]],
            ['PT5H30M10S', [5, 30, 10, 0]],
            ['PT1H10S', [1, 0, 10, 0]],
            ['PT10.5S', [0, 0, 10, 500_000_000]],
            ['PT0.123456789S', [0, 0, 0, 123_456_789]],
            ['PT0.1S', [0, 0, 0, 100_000_000]],
            ['-PT1H', [-1, 0, 0, 0]],
            ['-PT5H30M10S', [-5, -30, -10, 0]],
            ['-PT10.5S', [0, 0, -10, -500_000_000]],
        ];
    }

    #[DataProvider('provideFromIso8601')]
    public function testFromIso8601(string $iso, array $expectedParts): void
    {
        static::assertSame($expectedParts, DateTime\Duration::fromIso8601($iso)->getParts());
    }

    public function testFromIso8601RoundTrip(): void
    {
        $d = DateTime\Duration::fromParts(5, 30, 10, 500_000_000);

        static::assertTrue($d->equals(DateTime\Duration::fromIso8601($d->toIso8601())));
    }

    public function testFromIso8601EmptyString(): void
    {
        $this->expectException(DateTime\Exception\ParserException::class);
        $this->expectExceptionMessage('Invalid ISO 8601 duration "".');

        DateTime\Duration::fromIso8601('');
    }

    public function testFromIso8601MissingP(): void
    {
        $this->expectException(DateTime\Exception\ParserException::class);
        $this->expectExceptionMessage('Invalid ISO 8601 duration "T5H".');

        DateTime\Duration::fromIso8601('T5H');
    }

    public function testFromIso8601MissingT(): void
    {
        $this->expectException(DateTime\Exception\ParserException::class);
        $this->expectExceptionMessage('contains date components; use Period::fromIso8601() instead.');

        DateTime\Duration::fromIso8601('P5H');
    }

    public function testFromIso8601RejectsDateComponent(): void
    {
        $this->expectException(DateTime\Exception\ParserException::class);
        $this->expectExceptionMessage('contains date components; use Period::fromIso8601() instead.');

        DateTime\Duration::fromIso8601('P1Y');
    }

    public function testFromIso8601InvalidFormat(): void
    {
        $this->expectException(DateTime\Exception\ParserException::class);
        $this->expectExceptionMessage('Invalid ISO 8601 duration "PTABC".');

        DateTime\Duration::fromIso8601('PTABC');
    }

    public function testAddToTimestamp(): void
    {
        $ts = DateTime\Timestamp::fromParts(1000, 0);
        $duration = DateTime\Duration::fromParts(1, 30, 0);

        $result = $duration->addTo($ts);

        static::assertSame(1000 + 3600 + 1800, $result->getTimestamp()->getSeconds());
    }

    public function testAddToDateTime(): void
    {
        $dt = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 1, 1, 10, 0, 0);
        $duration = DateTime\Duration::hours(3);

        $result = $duration->addTo($dt);

        static::assertSame(3 * 3600, $result->getTimestamp()->getSeconds() - $dt->getTimestamp()->getSeconds());
    }

    public function testSubtractFromTimestamp(): void
    {
        $ts = DateTime\Timestamp::fromParts(5000, 0);
        $duration = DateTime\Duration::seconds(500);

        $result = $duration->subtractFrom($ts);

        static::assertSame(4500, $result->getTimestamp()->getSeconds());
    }

    public function testEqualsWithNonDuration(): void
    {
        $duration = DateTime\Duration::hours(1);
        $period = DateTime\Period::days(1);

        static::assertFalse($duration->equals($period));
    }

    public function testMicrosecondsZeroParamsDefaultToZero(): void
    {
        $d = DateTime\Duration::microseconds(1000);

        static::assertSame(0, $d->getHours());
        static::assertSame(0, $d->getMinutes());
        static::assertSame(0, $d->getSeconds());
        static::assertSame(1_000_000, $d->getNanoseconds());
    }

    public function testGetPartsReturnsExactlyFourElements(): void
    {
        $d = DateTime\Duration::fromParts(1, 2, 3, 4);
        $parts = $d->getParts();

        static::assertCount(4, $parts);
        static::assertSame(1, $parts[0]);
        static::assertSame(2, $parts[1]);
        static::assertSame(3, $parts[2]);
        static::assertSame(4, $parts[3]);
    }

    public function testLargestDurations(): void
    {
        $maxHours = intdiv(PHP_INT_MAX, 3600);

        static::assertSame([$maxHours, 0, 0, 0], DateTime\Duration::hours($maxHours)->getParts());
        static::assertSame([-$maxHours, 0, 0, 0], DateTime\Duration::hours(-$maxHours)->getParts());
        static::assertSame([$maxHours, 30, 7, 0], DateTime\Duration::seconds(PHP_INT_MAX)->getParts());
        static::assertSame([-$maxHours, -30, -7, 0], DateTime\Duration::seconds(-PHP_INT_MAX)->getParts());
        // The range is symmetric, so every duration can be negated.
        static::assertSame([$maxHours, 30, 7, 0], DateTime\Duration::seconds(-PHP_INT_MAX)->invert()->getParts());
        static::assertSame([-$maxHours, -30, -7, 0], DateTime\Duration::seconds(PHP_INT_MAX)->invert()->getParts());
        static::assertSame(
            [2_562_047_788_015, 12, 55, 807_000_000],
            DateTime\Duration::milliseconds(PHP_INT_MAX)->getParts(),
        );
        static::assertSame(
            [2_562_047_788, 0, 54, 775_807_000],
            DateTime\Duration::microseconds(PHP_INT_MAX)->getParts(),
        );
        static::assertSame(
            [-2_562_047, -47, -16, -854_775_808],
            DateTime\Duration::nanoseconds(PHP_INT_MIN)->getParts(),
        );
        // 3e15 hours overflow when converted to seconds on their own, but cancel out with the minutes.
        static::assertSame(
            [0, 0, 1, 0],
            DateTime\Duration::fromParts(3_000_000_000_000_000, -180_000_000_000_000_000, 1)->getParts(),
        );
    }

    /**
     * @return iterable<array{Closure(): DateTime\Duration, class-string<Throwable>}>
     */
    public static function provideOverflowingDurations(): iterable
    {
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::seconds(PHP_INT_MIN),
            DateTime\Exception\UnderflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::fromParts(0, 0, -PHP_INT_MAX, -1_000_000_000),
            DateTime\Exception\UnderflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::hours(PHP_INT_MAX),
            DateTime\Exception\OverflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::hours(PHP_INT_MIN),
            DateTime\Exception\UnderflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::minutes(PHP_INT_MAX),
            DateTime\Exception\OverflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::hours(intdiv(PHP_INT_MAX, 3600) + 1),
            DateTime\Exception\OverflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::fromParts(0, 0, PHP_INT_MAX, 1_000_000_000),
            DateTime\Exception\OverflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::seconds(1)->withHours(PHP_INT_MAX),
            DateTime\Exception\OverflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::seconds(PHP_INT_MAX)->plus(DateTime\Duration::seconds(
                1,
            )),
            DateTime\Exception\OverflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::seconds(PHP_INT_MIN)->minus(DateTime\Duration::seconds(
                1,
            )),
            DateTime\Exception\UnderflowException::class,
        ];
        // The exception follows the sign of the total, not of the first part that does not fit.
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::fromParts(0, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX),
            DateTime\Exception\UnderflowException::class,
        ];
        yield [
            static fn(): DateTime\Duration => DateTime\Duration::fromParts(0, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN),
            DateTime\Exception\OverflowException::class,
        ];
    }

    /**
     * @param Closure(): DateTime\Duration $operation
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('provideOverflowingDurations')]
    public function testOverflowingDurationsThrow(Closure $operation, string $exception): void
    {
        $this->expectException($exception);

        $operation();
    }

    public function testPartsThatDoNotFitOnTheirOwnButWhoseTotalDoes(): void
    {
        static::assertSame(
            [2_562_047_788_014_215, 30, 8, 500_000_000],
            DateTime\Duration::fromParts(-1000, 0, PHP_INT_MAX, 1_500_000_000)->getParts(),
        );
        static::assertSame(
            [-2_562_047_788_015_214, -30, -8, 0],
            DateTime\Duration::fromParts(1, 0, -PHP_INT_MAX, -1_000_000_000)->getParts(),
        );
    }

    public function testToStdlibLongDurationsAndFractions(): void
    {
        static::assertSame(3_600_000_000_000_000, DateTime\Duration::hours(1_000_000_000_000)->toStdlib()->s);
        static::assertSame(PHP_INT_MAX, DateTime\Duration::seconds(PHP_INT_MAX)->toStdlib()->s);
        static::assertSame(-PHP_INT_MAX, DateTime\Duration::seconds(-PHP_INT_MAX)->toStdlib()->s);

        $start = new DateTimeImmutable('2024-01-01 00:00:00.000000', new DateTimeZone('UTC'));

        $interval = DateTime\Duration::fromParts(1, 30, 45, 250_999_999)->toStdlib();
        static::assertSame(5445, $interval->s);
        static::assertSame(0.250_999, $interval->f);
        static::assertSame('2024-01-01 01:30:45.250999', $start->add($interval)->format('Y-m-d H:i:s.u'));

        $interval = DateTime\Duration::milliseconds(-1500)->toStdlib();
        static::assertSame(-1, $interval->s);
        static::assertSame(-0.5, $interval->f);
        static::assertSame('2023-12-31 23:59:58.500000', $start->add($interval)->format('Y-m-d H:i:s.u'));
    }
}
