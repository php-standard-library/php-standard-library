<?php

declare(strict_types=1);

namespace Psl\DateTime;

use DateTimeImmutable;
use Override;
use Psl\Interoperability;
use Psl\Locale\Locale;

use function intdiv;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Represents a precise point in time, with seconds and nanoseconds since the Unix epoch.
 *
 * @immutable
 *
 * @implements Interoperability\FromStdlib<DateTimeImmutable>
 *
 * @api
 */
final readonly class Timestamp implements TemporalInterface, Interoperability\FromStdlib
{
    use TemporalConvenienceMethodsTrait;

    /**
     * @param int $seconds
     * @param int<0, 999999999> $nanoseconds
     *
     * @pure
     */
    private function __construct(
        private int $seconds,
        private int $nanoseconds,
    ) {}

    /**
     * Creates a timestamp from seconds and nanoseconds since the epoch.
     *
     * Normalizes so nanoseconds are within 0-999999999. For instance:
     * - `fromRaw(42, -100)` becomes (41, 999999900).
     * - `fromRaw(-42, -100)` becomes (-43, 999999900).
     * - `fromRaw(42, 1000000100)` becomes (43, 100).
     *
     * @param int $seconds Seconds since the epoch.
     * @param int $nanoseconds Additional nanoseconds to adjust by.
     *
     * @throws Exception\OverflowException
     * @throws Exception\UnderflowException
     *
     * @pure
     */
    public static function fromParts(int $seconds, int $nanoseconds = 0): Timestamp
    {
        $secondsAdjustment = intdiv($nanoseconds, namespace\NANOSECONDS_PER_SECOND);
        $adjustedNanoseconds = $nanoseconds % namespace\NANOSECONDS_PER_SECOND;
        if ($adjustedNanoseconds < 0) {
            --$secondsAdjustment;
            $adjustedNanoseconds += namespace\NANOSECONDS_PER_SECOND;
        }

        // Check for an overflow or underflow before adjusting the seconds.
        if ($secondsAdjustment > 0 && $seconds > (PHP_INT_MAX - $secondsAdjustment)) {
            throw new Exception\OverflowException('Adding nanoseconds would cause an overflow.');
        }

        if ($secondsAdjustment < 0 && $seconds < (PHP_INT_MIN - $secondsAdjustment)) {
            throw new Exception\UnderflowException('Subtracting nanoseconds would cause an underflow.');
        }

        return new self($seconds + $secondsAdjustment, $adjustedNanoseconds);
    }

    /**
     * Create a high-precision instance representing the current time using the system clock.
     */
    public static function now(): self
    {
        [$seconds, $nanoseconds] = Internal\system_time();

        return self::fromParts($seconds, $nanoseconds);
    }

    /**
     * Create a current time instance using a monotonic clock with high precision
     *  to the nanosecond for precise measurements.
     *
     * This method ensures that the time is always moving forward, unaffected by adjustments in the system clock,
     * making it suitable for measuring durations or intervals accurately.
     *
     * @throws Exception\RuntimeException If the system does not provide a monotonic timer.
     */
    public static function monotonic(): self
    {
        [$seconds, $nanoseconds] = Internal\high_resolution_time();

        return self::fromParts($seconds, $nanoseconds);
    }

    /**
     * Creates a timestamp from milliseconds since the Unix epoch.
     *
     * @param int $milliseconds Milliseconds since the epoch. Can be negative for times before the epoch.
     *
     * @throws Exception\OverflowException
     * @throws Exception\UnderflowException
     *
     * @pure
     */
    public static function fromMilliseconds(int $milliseconds): self
    {
        $seconds = intdiv($milliseconds, namespace\MILLISECONDS_PER_SECOND);
        $remainingMs = $milliseconds % namespace\MILLISECONDS_PER_SECOND;

        return self::fromParts($seconds, $remainingMs * namespace\NANOSECONDS_PER_MILLISECOND);
    }

    /**
     * Creates a timestamp from microseconds since the Unix epoch.
     *
     * @param int $microseconds Microseconds since the epoch. Can be negative for times before the epoch.
     *
     * @throws Exception\OverflowException
     * @throws Exception\UnderflowException
     *
     * @pure
     */
    public static function fromMicroseconds(int $microseconds): self
    {
        $seconds = intdiv($microseconds, namespace\MICROSECONDS_PER_SECOND);
        $remainingUs = $microseconds % namespace\MICROSECONDS_PER_SECOND;

        return self::fromParts($seconds, $remainingUs * namespace\NANOSECONDS_PER_MICROSECOND);
    }

    /**
     * Parses a date and time string into an instance of {@see Timestamp} using a specific format pattern, with optional customization for timezone and locale.
     *
     * This method is specifically designed for cases where a custom format pattern is used to parse the input string.
     *
     * It allows for precise control over the parsing process by specifying the exact format pattern that matches the input string.
     *
     * Additionally, the method supports specifying a timezone and locale for parsing, enabling accurate interpretation of locale-specific formats.
     *
     * Fractional seconds in the input (e.g. the `SSS` field) are kept, to the millisecond.
     *
     * Example usage:
     *
     * ```php
     * $rawString = '2023-03-15 12:00:00';
     * $parsed_timestamp = DateTime\Timestamp::parse($rawString, 'yyyy-MM-dd HH:mm:ss', DateTime\Timezone::UTC, Locale\Locale::English);
     * ```
     *
     * @param string $rawString The date and time string to parse.
     * @param null|FormatPattern|string $pattern The custom format pattern for parsing the date and time. If null, uses a default pattern.
     * @param null|Timezone $timezone Optional timezone for parsing. If null, uses the system's default timezone.
     * @param null|Locale $locale Optional locale for parsing. If null, uses the system's default locale.
     *
     * @throws Exception\RuntimeException If the parsing process fails.
     *
     * @return static Returns an instance of {@see Timestamp} representing the parsed date and time.
     *
     * @see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
     * @see TemporalInterface::format()
     *
     * @psalm-mutation-free
     */
    public static function parse(
        string $rawString,
        null|FormatPattern|string $pattern = null,
        null|Timezone $timezone = null,
        null|Locale $locale = null,
    ): static {
        [$seconds, $nanoseconds] = Internal\parse(
            rawString: $rawString,
            pattern: $pattern,
            timezone: $timezone,
            locale: $locale,
        );

        return self::fromParts($seconds, $nanoseconds);
    }

    /**
     * Creates an instance of {@see Timestamp} from a date and time string, formatted according to specified styles for date and time,
     * with optional customization for timezone and locale.
     *
     * This method provides a more abstracted approach to parsing, allowing users  to specify styles rather than a custom pattern.
     *
     * This is particularly useful for parsing strings that follow common date and time formats.
     *
     * Additionally, the timezone and locale parameters enable accurate parsing of strings in locale-specific formats.
     *
     * Example usage:
     *
     * ```php
     * $rawString = "March 15, 2023 at 12:00 PM";
     *
     * $timestamp = DateTime\Timestamp::fromString($rawString, DateTime\DateStyle::Long, DateTime\TimeStyle::Short, DateTime\Timezone::UTC, Locale\Locale::English);
     * ```
     *
     * @param string $rawString The date and time string to parse.
     * @param null|DateStyle $dateStyle The style for the date portion of the string. If null, a default style is used.
     * @param null|TimeStyle $timeStyle The style for the time portion of the string. If null, a default style is used.
     * @param null|Timezone $timezone Optional timezone for parsing. If null, uses the system's default timezone.
     * @param null|Locale $locale Optional locale for parsing. If null, uses the system's default locale.
     *
     * @throws Exception\RuntimeException If the parsing process fails.
     *
     * @return static Returns an instance of {@see Timestamp} representing the parsed date and time.
     *
     * @see TemporalInterface::toString()
     *
     * @psalm-mutation-free
     */
    public static function fromString(
        string $rawString,
        null|DateStyle $dateStyle = null,
        null|TimeStyle $timeStyle = null,
        null|Timezone $timezone = null,
        null|Locale $locale = null,
    ): static {
        [$seconds, $nanoseconds] = Internal\parse(
            rawString: $rawString,
            dateStyle: $dateStyle,
            timeStyle: $timeStyle,
            timezone: $timezone,
            locale: $locale,
        );

        return self::fromParts($seconds, $nanoseconds);
    }

    /**
     * Returns this Timestamp instance itself, as it already represents a timestamp.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getTimestamp(): self
    {
        return $this;
    }

    /**
     * Returns the {@see Timestamp} parts (seconds, nanoseconds).
     *
     * @return array{int, int<0, 999999999>}
     *
     * @psalm-mutation-free
     */
    public function toParts(): array
    {
        return [$this->seconds, $this->nanoseconds];
    }

    /**
     * Returns the number of seconds since the Unix epoch represented by this timestamp.
     *
     * @return int Seconds since the epoch. Can be negative for times before the epoch.
     *
     * @psalm-mutation-free
     */
    public function getSeconds(): int
    {
        return $this->seconds;
    }

    /**
     * Returns the nanoseconds part of this timestamp.
     *
     * @return int<0, 999999999> The nanoseconds part, ranging from 0 to 999999999.
     *
     * @psalm-mutation-free
     */
    public function getNanoseconds(): int
    {
        return $this->nanoseconds;
    }

    /**
     * Adds the specified duration to this timestamp object, returning a new instance with the added duration.
     *
     * @throws Exception\InvalidArgumentException If the amount is not a Duration.
     * @throws Exception\UnderflowException If adding the duration results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the duration results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function plus(TemporalAmountInterface $amount): static
    {
        if (!$amount instanceof Duration) {
            throw new Exception\InvalidArgumentException(
                'Timestamp only supports Duration; use a DateTime for calendar-based arithmetic.',
            );
        }

        [$h, $m, $s, $ns] = $amount->getParts();
        $totalSeconds = (namespace\SECONDS_PER_MINUTE * $m) + (namespace\SECONDS_PER_HOUR * $h) + $s;
        if ($totalSeconds > 0 && $this->seconds > (PHP_INT_MAX - $totalSeconds)) {
            throw new Exception\OverflowException('Adding the duration would cause an overflow.');
        }

        if ($totalSeconds < 0 && $this->seconds < (PHP_INT_MIN - $totalSeconds)) {
            throw new Exception\UnderflowException('Adding the duration would cause an underflow.');
        }

        $newSeconds = $this->seconds + $totalSeconds;
        $newNanoseconds = $this->nanoseconds + $ns;

        return self::fromParts($newSeconds, $newNanoseconds);
    }

    /**
     * Subtracts the specified temporal amount from this timestamp object, returning a new instance.
     *
     * Only {@see Duration} is supported. For calendar-based arithmetic, use a {@see DateTimeInterface} instead.
     *
     * @throws Exception\InvalidArgumentException If the amount is not a Duration.
     * @throws Exception\UnderflowException If subtracting the duration results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the duration results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function minus(TemporalAmountInterface $amount): static
    {
        if (!$amount instanceof Duration) {
            throw new Exception\InvalidArgumentException(
                'Timestamp only supports Duration; use a DateTime for calendar-based arithmetic.',
            );
        }

        [$h, $m, $s, $ns] = $amount->getParts();
        $totalSeconds = (namespace\SECONDS_PER_MINUTE * $m) + (namespace\SECONDS_PER_HOUR * $h) + $s;
        if ($totalSeconds < 0 && $this->seconds > (PHP_INT_MAX + $totalSeconds)) {
            throw new Exception\OverflowException('Subtracting the duration would cause an overflow.');
        }

        if ($totalSeconds > 0 && $this->seconds < (PHP_INT_MIN + $totalSeconds)) {
            throw new Exception\UnderflowException('Subtracting the duration would cause an underflow.');
        }

        $newSeconds = $this->seconds - $totalSeconds;
        $newNanoseconds = $this->nanoseconds - $ns;

        return self::fromParts($newSeconds, $newNanoseconds);
    }

    /**
     * Creates a {@see Timestamp} from a PHP {@see DateTimeImmutable}.
     *
     * @param DateTimeImmutable $value
     *
     * @psalm-mutation-free
     */
    #[Override]
    public static function fromStdlib(mixed $value): static
    {
        $seconds = $value->getTimestamp();
        $microseconds = (int) $value->format('u');
        $nanoseconds = $microseconds * namespace\NANOSECONDS_PER_MICROSECOND;

        return self::fromParts($seconds, $nanoseconds);
    }

    /**
     * @return array{seconds: int, nanoseconds: int<0, 999999999>}
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'seconds' => $this->seconds,
            'nanoseconds' => $this->nanoseconds,
        ];
    }
}
