<?php

declare(strict_types=1);

namespace Psl\DateTime;

use DateInterval;
use Override;
use Psl\Comparison;

use function abs;
use function implode;
use function intdiv;
use function rtrim;
use function str_pad;
use function substr;

use const STR_PAD_LEFT;

/**
 * Defines a representation of a time duration with specific hours, minutes, seconds,
 * and nanoseconds.
 *
 * All instances are normalized as follows:
 *
 * - all non-zero parts (hours, minutes, seconds, nanoseconds) will have the same sign
 * - minutes, seconds will be between -59 and 59
 * - nanoseconds will be between -999999999 and 999999999 (less than 1 second)
 *
 * For example, Duration::hours(2, -183) normalizes to "-1 hour(s), -3 minute(s)".
 *
 * The total number of whole seconds of a duration is always within -PHP_INT_MAX..PHP_INT_MAX (so a duration
 * can always be negated); operations whose result would fall outside of that range throw
 * {@see Exception\OverflowException} or {@see Exception\UnderflowException}.
 *
 * @implements Comparison\Comparable<Duration>
 *
 * @immutable
 *
 * @api
 */
final readonly class Duration implements TemporalAmountInterface, Comparison\Comparable
{
    /**
     * Initializes a new instance of Duration with specified hours, minutes, seconds, and
     * nanoseconds.
     *
     * @param int $hours
     * @param int<-59, 59> $minutes
     * @param int<-59, 59> $seconds
     * @param int<-999999999, 999999999> $nanoseconds
     *
     * @pure
     */
    private function __construct(
        private int $hours,
        private int $minutes,
        private int $seconds,
        private int $nanoseconds,
    ) {}

    /**
     * Returns an instance representing the specified number of hours (and
     * optionally minutes, seconds, nanoseconds). Due to normalization, the
     * actual values in the returned instance may differ from the provided ones.
     *
     * @throws Exception\OverflowException If the total number of seconds is greater than PHP_INT_MAX.
     * @throws Exception\UnderflowException If the total number of seconds is less than -PHP_INT_MAX.
     *
     * @pure
     */
    public static function fromParts(int $hours, int $minutes = 0, int $seconds = 0, int $nanoseconds = 0): self
    {
        // This is where the normalization happens.
        [$h, $m, $s, $ns] = Internal\normalize_duration_parts($hours, $minutes, $seconds, $nanoseconds);

        return new self($h, $m, $s, $ns);
    }

    /**
     * Returns an instance representing the specified number of hours.
     *
     * @pure
     */
    public static function hours(int $hours): self
    {
        return self::fromParts($hours);
    }

    /**
     * Returns an instance representing the specified number of minutes. Due to
     * normalization, the actual value in the returned instance may differ from
     * the provided one, and the resulting instance may contain larger units.
     *
     * For example, `Duration::minutes(63)` normalizes to "1 hour(s), 3 minute(s)".
     *
     * @pure
     */
    public static function minutes(int $minutes): self
    {
        return self::fromParts(0, $minutes);
    }

    /**
     * Returns an instance representing the specified number of seconds. Due to
     * normalization, the actual value in the returned instance may differ from
     * the provided one, and the resulting instance may contain larger units.
     *
     * For example, `Duration::seconds(63)` normalizes to "1 minute(s), 3 second(s)".
     *
     * @pure
     */
    public static function seconds(int $seconds): self
    {
        return self::fromParts(0, 0, $seconds);
    }

    /**
     * Returns an instance representing the specified number of milliseconds (ms).
     * The value is converted and stored as nanoseconds, since that is the only
     * unit smaller than a second that we support. Due to normalization, the
     * resulting instance may contain larger units.
     *
     * For example, `Duration::milliseconds(8042)` normalizes to "8 second(s), 42000000 nanosecond(s)".
     *
     * @pure
     */
    public static function milliseconds(int $milliseconds): self
    {
        return self::fromParts(
            0,
            0,
            intdiv($milliseconds, namespace\MILLISECONDS_PER_SECOND),
            ($milliseconds % namespace\MILLISECONDS_PER_SECOND) * namespace\NANOSECONDS_PER_MILLISECOND,
        );
    }

    /**
     * Returns an instance representing the specified number of microseconds (us).
     * The value is converted and stored as nanoseconds, since that is the only
     * unit smaller than a second that we support. Due to normalization, the
     * resulting instance may contain larger units.
     *
     * For example, `Duration::microseconds(8000042)` normalizes to "8 second(s), 42000 nanosecond(s)".
     *
     * @pure
     */
    public static function microseconds(int $microseconds): self
    {
        return self::fromParts(
            0,
            0,
            intdiv($microseconds, namespace\MICROSECONDS_PER_SECOND),
            ($microseconds % namespace\MICROSECONDS_PER_SECOND) * namespace\NANOSECONDS_PER_MICROSECOND,
        );
    }

    /**
     * Returns an instance representing the specified number of nanoseconds (ns).
     * Due to normalization, the resulting instance may contain larger units.
     *
     * For example, `Duration::nanoseconds(8000000042)` normalizes to "8 second(s), 42 nanosecond(s)".
     *
     * @pure
     */
    public static function nanoseconds(int $nanoseconds): self
    {
        return self::fromParts(0, 0, 0, $nanoseconds);
    }

    /**
     * Returns an instance with all parts equal to 0.
     *
     * @pure
     */
    public static function zero(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * Compiles and returns the duration's components (hours, minutes, seconds, nanoseconds) in an
     * array, in descending order of significance.
     *
     * @return array{int, int, int, int}
     *
     * @psalm-mutation-free
     */
    public function getParts(): array
    {
        return [$this->hours, $this->minutes, $this->seconds, $this->nanoseconds];
    }

    /**
     * Returns the "hours" part of this time duration.
     *
     * @psalm-mutation-free
     */
    public function getHours(): int
    {
        return $this->hours;
    }

    /**
     * Returns the "minutes" part of this time duration.
     *
     * @psalm-mutation-free
     */
    public function getMinutes(): int
    {
        return $this->minutes;
    }

    /**
     * Returns the "seconds" part of this time duration.
     *
     * @psalm-mutation-free
     */
    public function getSeconds(): int
    {
        return $this->seconds;
    }

    /**
     * Returns the "nanoseconds" part of this time duration.
     *
     * @psalm-mutation-free
     */
    public function getNanoseconds(): int
    {
        return $this->nanoseconds;
    }

    /**
     * Computes, and returns the total duration of the instance in hours as a floating-point number,
     * including any fractional parts.
     *
     * @psalm-mutation-free
     */
    public function getTotalHours(): float
    {
        return (
            $this->hours
            + ($this->minutes / namespace\MINUTES_PER_HOUR)
            + ($this->seconds / namespace\SECONDS_PER_HOUR)
            + ($this->nanoseconds / (namespace\SECONDS_PER_HOUR * namespace\NANOSECONDS_PER_SECOND))
        );
    }

    /**
     * Computes, and returns the total duration of the instance in minutes as a floating-point number,
     * including any fractional parts.
     *
     * @psalm-mutation-free
     */
    public function getTotalMinutes(): float
    {
        return (
            ($this->hours * namespace\MINUTES_PER_HOUR)
            + $this->minutes
            + ($this->seconds / namespace\SECONDS_PER_MINUTE)
            + ($this->nanoseconds / (namespace\SECONDS_PER_MINUTE * namespace\NANOSECONDS_PER_SECOND))
        );
    }

    /**
     * Computes, and returns the total duration of the instance in seconds as a floating-point number,
     * including any fractional parts.
     *
     * @psalm-mutation-free
     */
    public function getTotalSeconds(): float
    {
        return (
            $this->seconds
            + ($this->minutes * namespace\SECONDS_PER_MINUTE)
            + ($this->hours * namespace\SECONDS_PER_HOUR)
            + ($this->nanoseconds / namespace\NANOSECONDS_PER_SECOND)
        );
    }

    /**
     * Computes, and returns the total duration of the instance in milliseconds as a floating-point number,
     * including any fractional parts.
     *
     * @psalm-mutation-free
     */
    public function getTotalMilliseconds(): float
    {
        return (
            ($this->hours * namespace\SECONDS_PER_HOUR * namespace\MILLISECONDS_PER_SECOND)
            + ($this->minutes * namespace\SECONDS_PER_MINUTE * namespace\MILLISECONDS_PER_SECOND)
            + ($this->seconds * namespace\MILLISECONDS_PER_SECOND)
            + ($this->nanoseconds / namespace\NANOSECONDS_PER_MILLISECOND)
        );
    }

    /**
     * Computes, and returns the total duration of the instance in microseconds as a floating-point number,
     * including any fractional parts.
     *
     * @psalm-mutation-free
     */
    public function getTotalMicroseconds(): float
    {
        return (
            ($this->hours * namespace\SECONDS_PER_HOUR * namespace\MICROSECONDS_PER_SECOND)
            + ($this->minutes * namespace\SECONDS_PER_MINUTE * namespace\MICROSECONDS_PER_SECOND)
            + ($this->seconds * namespace\MICROSECONDS_PER_SECOND)
            + ($this->nanoseconds / namespace\NANOSECONDS_PER_MICROSECOND)
        );
    }

    /**
     * Determines whether the instance represents a zero duration.
     *
     * @psalm-mutation-free
     */
    public function isZero(): bool
    {
        return 0 === $this->hours && 0 === $this->minutes && 0 === $this->seconds && 0 === $this->nanoseconds;
    }

    /**
     * Checks if the duration is positive, implying that all non-zero components are positive.
     *
     * Due to normalization, it is guaranteed that a positive time duration will
     * have all of its parts (hours, minutes, seconds, nanoseconds) positive or
     * equal to 0.
     *
     * Note that this method returns false if all parts are equal to 0.
     *
     * @psalm-mutation-free
     */
    public function isPositive(): bool
    {
        return $this->hours > 0 || $this->minutes > 0 || $this->seconds > 0 || $this->nanoseconds > 0;
    }

    /**
     * Checks if the duration is negative, implying that all non-zero components are negative.
     *
     * Due to normalization, it is guaranteed that a negative time duration will
     * have all of its parts (hours, minutes, seconds, nanoseconds) negative or
     * equal to 0.
     *
     * Note that this method returns false if all parts are equal to 0.
     *
     * @psalm-mutation-free
     */
    public function isNegative(): bool
    {
        return $this->hours < 0 || $this->minutes < 0 || $this->seconds < 0 || $this->nanoseconds < 0;
    }

    /**
     * Returns a new instance with the "hours" part changed to the specified
     * value.
     *
     * Note that due to normalization, the actual value in the returned
     * instance may differ, and this may affect other parts of the returned
     * instance too.
     *
     * For example, `Duration::hours(2, 30)->withHours(-1)` is equivalent to
     * `Duration::hours(-1, 30)` which normalizes to "-30 minute(s)".
     *
     * @psalm-mutation-free
     */
    public function withHours(int $hours): self
    {
        return self::fromParts($hours, $this->minutes, $this->seconds, $this->nanoseconds);
    }

    /**
     * Returns a new instance with the "minutes" part changed to the specified
     * value.
     *
     * Note that due to normalization, the actual value in the returned
     * instance may differ, and this may affect other parts of the returned
     * instance too.
     *
     * For example, `Duration::minutes(2, 30)->withMinutes(-1)` is equivalent to
     * `Duration::minutes(-1, 30)` which normalizes to "-30 second(s)".
     *
     * @psalm-mutation-free
     */
    public function withMinutes(int $minutes): self
    {
        return self::fromParts($this->hours, $minutes, $this->seconds, $this->nanoseconds);
    }

    /**
     * Returns a new instance with the "seconds" part changed to the specified
     * value.
     *
     * Note that due to normalization, the actual value in the returned
     * instance may differ, and this may affect other parts of the returned
     * instance too.
     *
     * For example, `Duration::minutes(2, 30)->withSeconds(-30)` is equivalent
     * to `Duration::minutes(2, -30)` which normalizes to "1 minute(s), 30 second(s)".
     *
     * @psalm-mutation-free
     */
    public function withSeconds(int $seconds): self
    {
        return self::fromParts($this->hours, $this->minutes, $seconds, $this->nanoseconds);
    }

    /**
     * Returns a new instance with the "nanoseconds" part changed to the specified
     * value.
     *
     * Note that due to normalization, the actual value in the returned
     * instance may differ, and this may affect other parts of the returned
     * instance too.
     *
     * For example, `Duration::seconds(2)->withNanoseconds(-1)` is equivalent
     * to `Duration::seconds(2, -1)` which normalizes to "1 second(s), 999999999 nanosecond(s)".
     *
     * @psalm-mutation-free
     */
    public function withNanoseconds(int $nanoseconds): self
    {
        return self::fromParts($this->hours, $this->minutes, $this->seconds, $nanoseconds);
    }

    /**
     * Implements a comparison between this duration and another, based on their duration.
     *
     * @param Duration $other
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function compare(mixed $other): Comparison\Order
    {
        if ($this->hours !== $other->hours) {
            return Comparison\Order::from($this->hours <=> $other->hours);
        }

        if ($this->minutes !== $other->minutes) {
            return Comparison\Order::from($this->minutes <=> $other->minutes);
        }

        if ($this->seconds !== $other->seconds) {
            return Comparison\Order::from($this->seconds <=> $other->seconds);
        }

        return Comparison\Order::from($this->nanoseconds <=> $other->nanoseconds);
    }

    /**
     * Evaluates whether this duration is equivalent to another, considering all time components.
     *
     * @param TemporalAmountInterface $other
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function equals(mixed $other): bool
    {
        if (!$other instanceof Duration) {
            return false;
        }

        return $this->compare($other) === Comparison\Order::Equal;
    }

    /**
     * Determines if this duration is shorter than another.
     *
     * @psalm-mutation-free
     */
    public function shorter(self $other): bool
    {
        return $this->compare($other) === Comparison\Order::Less;
    }

    /**
     * Determines if this duration is shorter than, or equivalent to another.
     *
     * @psalm-mutation-free
     */
    public function shorterOrEqual(self $other): bool
    {
        return $this->compare($other) !== Comparison\Order::Greater;
    }

    /**
     * Determines if this duration is longer than another.
     *
     * @psalm-mutation-free
     */
    public function longer(self $other): bool
    {
        return $this->compare($other) === Comparison\Order::Greater;
    }

    /**
     * Determines if this duration is longer than, or equivalent to another.
     *
     * @psalm-mutation-free
     */
    public function longerOrEqual(self $other): bool
    {
        return $this->compare($other) !== Comparison\Order::Less;
    }

    /**
     * Returns true if this instance represents a time duration longer than $a but
     * shorter than $b, or vice-versa (shorter than $a but longer than $b), or if
     * this instance is equal to $a and/or $b. Returns false if this instance is
     * shorter/longer than both.
     *
     * @psalm-mutation-free
     */
    public function betweenInclusive(self $a, self $b): bool
    {
        $ca = $this->compare($a);
        $cb = $this->compare($b);

        return $ca === Comparison\Order::Equal || $ca !== $cb;
    }

    /**
     * Returns true if this instance represents a time duration longer than $a but
     * shorter than $b, or vice-versa (shorter than $a but longer than $b).
     * Returns false if this instance is equal to $a and/or $b, or shorter/longer
     * than both.
     *
     * @psalm-mutation-free
     */
    public function betweenExclusive(self $a, self $b): bool
    {
        $ca = $this->compare($a);
        $cb = $this->compare($b);

        return $ca !== Comparison\Order::Equal && $cb !== Comparison\Order::Equal && $ca !== $cb;
    }

    /**
     * Returns a new instance, converting a positive/negative duration to the
     * opposite (negative/positive) duration of equal length. The resulting
     * instance has all parts equivalent to the current instance's parts
     * multiplied by -1.
     *
     * @psalm-mutation-free
     */
    public function invert(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return new self(-$this->hours, -$this->minutes, -$this->seconds, -$this->nanoseconds);
    }

    /**
     * Returns a new instance representing the sum of this instance and the
     * provided `$other` instance. Note that time duration can be negative, so
     * the resulting instance is not guaranteed to be shorter/longer than either
     * of the inputs.
     *
     * This operation is commutative: `$a->plus($b) === $b->plus($a)`
     *
     * @psalm-mutation-free
     */
    public function plus(self $other): self
    {
        if ($other->isZero()) {
            return $this;
        }

        if ($this->isZero()) {
            return $other;
        }

        return self::fromParts(
            $this->hours + $other->hours,
            $this->minutes + $other->minutes,
            $this->seconds + $other->seconds,
            $this->nanoseconds + $other->nanoseconds,
        );
    }

    /**
     * Returns a new instance representing the difference between this instance
     * and the provided `$other` instance (i.e. `$other` subtracted from `$this`).
     * Note that time duration can be negative, so the resulting instance is not
     * guaranteed to be shorter/longer than either of the inputs.
     *
     * This operation is not commutative: `$a->minus($b) !== $b->minus($a)`
     * But: `$a->minus($b) === $b->minus($a)->invert()`
     *
     * @psalm-mutation-free
     */
    public function minus(self $other): self
    {
        if ($other->isZero()) {
            return $this;
        }

        if ($this->isZero()) {
            return $other->invert();
        }

        return self::fromParts(
            $this->hours - $other->hours,
            $this->minutes - $other->minutes,
            $this->seconds - $other->seconds,
            $this->nanoseconds - $other->nanoseconds,
        );
    }

    /**
     * Returns an ISO 8601 duration string representing this duration.
     *
     * Examples: "PT5H30M", "PT10.5S", "PT0S", "-PT1H30M".
     * Fractional seconds are used when nanoseconds are present.
     *
     * @psalm-mutation-free
     */
    public function toIso8601(): string
    {
        return Internal\format_iso8601_duration($this->hours, $this->minutes, $this->seconds, $this->nanoseconds);
    }

    /**
     * Parses an ISO 8601 duration string into a Duration.
     *
     * Accepts formats like "PT5H30M", "PT10.5S", "-PT1H", "PT0S".
     * Only time components (H, M, S after T) are accepted. Date components (Y, M, D before T)
     * will cause a {@see Exception\ParserException}.
     *
     * @throws Exception\ParserException If the string is not a valid ISO 8601 duration.
     *
     * @pure
     */
    public static function fromIso8601(string $value): self
    {
        [$hours, $minutes, $seconds, $nanoseconds] = Internal\parse_iso8601_duration($value);

        return self::fromParts($hours, $minutes, $seconds, $nanoseconds);
    }

    /**
     * Adds this duration to the given temporal, returning the result.
     *
     * @psalm-mutation-free
     */
    public function addTo(TemporalInterface $temporal): TemporalInterface
    {
        return $temporal->plus($this);
    }

    /**
     * Subtracts this duration from the given temporal, returning the result.
     *
     * @psalm-mutation-free
     */
    public function subtractFrom(TemporalInterface $temporal): TemporalInterface
    {
        return $temporal->minus($this);
    }

    /**
     * Returns the time duration as string, useful e.g. for debugging. This is not
     * meant to be a comprehensive way to format time durations for user-facing
     * output.
     *
     * @param int<0, max> $maxDecimals
     *
     * @psalm-mutation-free
     */
    public function toString(int $maxDecimals = 3): string
    {
        $decimalPart = '';
        if ($maxDecimals > 0) {
            $decimalPart = (string) abs($this->nanoseconds);
            $decimalPart = str_pad($decimalPart, 9, '0', STR_PAD_LEFT);
            $decimalPart = substr($decimalPart, 0, $maxDecimals);
            $decimalPart = rtrim($decimalPart, '0');
        }

        if ('' !== $decimalPart) {
            $decimalPart = '.' . $decimalPart;
        }

        $secSign = $this->seconds < 0 || $this->nanoseconds < 0 ? '-' : '';
        $sec = abs($this->seconds);

        $containsHours = 0 !== $this->hours;
        $containsMinutes = 0 !== $this->minutes;
        $concatenatedSeconds = $secSign . (string) $sec . $decimalPart;
        $containsSeconds = '0' !== $concatenatedSeconds;

        /** @var list<string> $output */
        $output = [];
        if ($containsHours) {
            $output[] = (string) $this->hours . ' hour(s)';
        }

        if ($containsMinutes || $containsHours && $containsSeconds) {
            $output[] = (string) $this->minutes . ' minute(s)';
        }

        if ($containsSeconds) {
            $output[] = $concatenatedSeconds . ' second(s)';
        }

        return [] === $output ? '0 second(s)' : implode(', ', $output);
    }

    /**
     * Returns a string representation of the time duration.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Converts this {@see Duration} to a PHP {@see DateInterval}.
     *
     * The whole duration is stored, signed, in the interval's seconds (`s`) and fraction (`f`) properties.
     *
     * Note: nanosecond precision is truncated to microseconds.
     *
     * @return DateInterval
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toStdlib(): DateInterval
    {
        // Integer arithmetic: `(int) $this->getTotalSeconds()` loses precision, or warns, for long durations,
        // and `DateInterval::createFromDateString()` rejects numbers of seconds with more than 12 digits.
        return DateInterval::__set_state([
            'y' => 0,
            'm' => 0,
            'd' => 0,
            'h' => 0,
            'i' => 0,
            's' =>
                ($this->hours * namespace\SECONDS_PER_HOUR)
                    + ($this->minutes * namespace\SECONDS_PER_MINUTE)
                    + $this->seconds,
            'f' =>
                intdiv($this->nanoseconds, namespace\NANOSECONDS_PER_MICROSECOND) / namespace\MICROSECONDS_PER_SECOND,
            'invert' => 0,
            'days' => false,
        ]);
    }

    /**
     * Returns data which can be serialized by json_encode().
     *
     * @return array{hours: int, minutes: int, seconds: int, nanoseconds: int}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'hours' => $this->hours,
            'minutes' => $this->minutes,
            'seconds' => $this->seconds,
            'nanoseconds' => $this->nanoseconds,
        ];
    }
}
