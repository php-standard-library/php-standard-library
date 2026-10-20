<?php

declare(strict_types=1);

namespace Psl\DateTime;

use Override;
use Psl\Comparison;
use Psl\Comparison\Order;
use Psl\Locale\Locale;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * @require-implements TemporalInterface
 *
 * @psalm-immutable
 *
 * @api
 */
trait TemporalConvenienceMethodsTrait
{
    /**
     * Compare this {@see TemporalInterface} object to the given one.
     *
     * @param TemporalInterface $other
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function compare(mixed $other): Order
    {
        $a = $this->getTimestamp()->toParts();
        $b = $other->getTimestamp()->toParts();

        return Comparison\Order::from($a[0] !== $b[0] ? $a[0] <=> $b[0] : $a[1] <=> $b[1]);
    }

    /**
     * Checks if this {@see TemporalInterface} object represents the same time as the given one.
     *
     * Note: this method is an alias for {@see TemporalInterface::atTheSameTime()}.
     *
     * @param TemporalInterface $other
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function equals(mixed $other): bool
    {
        return $this->atTheSameTime($other);
    }

    /**
     * Checks if this temporal object represents the same time as the given one.
     *
     * Note: this method is an alias for {@see TemporalInterface::equals()}.
     *
     * @psalm-mutation-free
     */
    public function atTheSameTime(TemporalInterface $other): bool
    {
        return $this->compare($other) === Comparison\Order::Equal;
    }

    /**
     * Checks if this temporal object is before the given one.
     *
     * @psalm-mutation-free
     */
    public function before(TemporalInterface $other): bool
    {
        return $this->compare($other) === Comparison\Order::Less;
    }

    /**
     * Checks if this temporal object is before or at the same time as the given one.
     *
     * @psalm-mutation-free
     */
    public function beforeOrAtTheSameTime(TemporalInterface $other): bool
    {
        return $this->compare($other) !== Comparison\Order::Greater;
    }

    /**
     * Checks if this temporal object is after the given one.
     *
     * @psalm-mutation-free
     */
    public function after(TemporalInterface $other): bool
    {
        return $this->compare($other) === Comparison\Order::Greater;
    }

    /**
     * Checks if this temporal object is after or at the same time as the given one.
     *
     * @psalm-mutation-free
     */
    public function afterOrAtTheSameTime(TemporalInterface $other): bool
    {
        return $this->compare($other) !== Comparison\Order::Less;
    }

    /**
     * Checks if this temporal object is between the given times (inclusive).
     *
     * @psalm-mutation-free
     */
    public function betweenTimeInclusive(TemporalInterface $a, TemporalInterface $b): bool
    {
        $ca = $this->compare($a);
        $cb = $this->compare($b);

        return $ca === Comparison\Order::Equal || $ca !== $cb;
    }

    /**
     * Checks if this temporal object is between the given times (exclusive).
     *
     * @psalm-mutation-free
     */
    public function betweenTimeExclusive(TemporalInterface $a, TemporalInterface $b): bool
    {
        $ca = $this->compare($a);
        $cb = $this->compare($b);

        return $ca !== Comparison\Order::Equal && $cb !== Comparison\Order::Equal && $ca !== $cb;
    }

    /**
     * Adds the specified hours to this temporal object, returning a new instance with the added hours.
     *
     * @throws Exception\UnderflowException If adding the hours results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the hours results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusHours(int $hours): static
    {
        return $this->plus(Duration::hours($hours));
    }

    /**
     * Adds the specified minutes to this temporal object, returning a new instance with the added minutes.
     *
     * @throws Exception\UnderflowException If adding the minutes results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the minutes results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusMinutes(int $minutes): static
    {
        return $this->plus(Duration::minutes($minutes));
    }

    /**
     * Adds the specified seconds to this temporal object, returning a new instance with the added seconds.
     *
     * @throws Exception\UnderflowException If adding the seconds results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the seconds results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusSeconds(int $seconds): static
    {
        return $this->plus(Duration::seconds($seconds));
    }

    /**
     * Adds the specified nanoseconds to this temporal object, returning a new instance with the added nanoseconds.
     *
     * @throws Exception\UnderflowException If adding the nanoseconds results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the nanoseconds results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusNanoseconds(int $nanoseconds): static
    {
        return $this->plus(Duration::nanoseconds($nanoseconds));
    }

    /**
     * Subtracts the specified hours from this temporal object, returning a new instance with the subtracted hours.
     *
     * @throws Exception\UnderflowException If subtracting the hours results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the hours results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusHours(int $hours): static
    {
        return $this->minus(Duration::hours($hours));
    }

    /**
     * Subtracts the specified minutes from this temporal object, returning a new instance with the subtracted minutes.
     *
     * @throws Exception\UnderflowException If subtracting the minutes results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the minutes results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusMinutes(int $minutes): static
    {
        return $this->minus(Duration::minutes($minutes));
    }

    /**
     * Subtracts the specified seconds from this temporal object, returning a new instance with the subtracted seconds.
     *
     * @throws Exception\UnderflowException If subtracting the seconds results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the seconds results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusSeconds(int $seconds): static
    {
        return $this->minus(Duration::seconds($seconds));
    }

    /**
     * Subtracts the specified nanoseconds from this temporal object, returning a new instance with the subtracted nanoseconds.
     *
     * @throws Exception\UnderflowException If subtracting the nanoseconds results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the nanoseconds results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusNanoseconds(int $nanoseconds): static
    {
        return $this->minus(Duration::nanoseconds($nanoseconds));
    }

    /**
     * Calculates the duration between this temporal object and the given one.
     *
     * @param TemporalInterface $other The temporal object to calculate the duration to.
     *
     * @throws Exception\OverflowException If the difference, in seconds, is greater than PHP_INT_MAX.
     * @throws Exception\UnderflowException If the difference, in seconds, is less than -PHP_INT_MAX.
     *
     * @return Duration The duration between the two temporal objects.
     *
     * @psalm-mutation-free
     */
    public function since(TemporalInterface $other): Duration
    {
        $a = $this->getTimestamp()->toParts();
        $b = $other->getTimestamp()->toParts();

        if ($b[0] < 0 && $a[0] > (PHP_INT_MAX + $b[0])) {
            throw new Exception\OverflowException('The duration between the two temporal objects is too long.');
        }

        if ($b[0] > 0 && $a[0] < (PHP_INT_MIN + $b[0])) {
            throw new Exception\UnderflowException('The duration between the two temporal objects is too long.');
        }

        return Duration::fromParts(0, 0, $a[0] - $b[0], $a[1] - $b[1]);
    }

    /**
     * Converts the current temporal object to a new {@see DateTimeInterface} instance in a different timezone.
     *
     * @param Timezone $timezone The target timezone for the conversion.
     *
     * @psalm-mutation-free
     */
    public function convertToTimezone(Timezone $timezone): DateTimeInterface
    {
        return DateTime::fromTimestamp($this->getTimestamp(), $timezone);
    }

    /**
     * Formats this {@see TemporalInterface} instance based on a specific pattern, with optional customization for timezone and locale.
     *
     * This method allows for detailed customization of the output string by specifying a format pattern. If no pattern is provided,
     * a default, implementation-specific pattern will be used. Additionally, the method supports specifying a timezone and locale
     * for further customization of the formatted output. If these are not provided, system defaults will be used.
     *
     * Example usage:
     *
     * ```php
     * $formatted = $temporal->format('yyyy-MM-dd HH:mm:ss', $timezone, $locale);
     * ```
     *
     * @param null|FormatPattern|string $pattern Optional custom format pattern for the date and time. If null, uses a default pattern.
     * @param null|Timezone $timezone Optional timezone for formatting. If null, uses the system's default timezone.
     * @param null|Locale $locale Optional locale for formatting. If null, uses the system's default locale.
     *
     * @return string The formatted date and time string, according to the specified pattern, timezone, and locale.
     *
     * @see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
     *
     * @psalm-mutation-free
     */
    public function format(
        null|FormatPattern|string $pattern = null,
        null|Timezone $timezone = null,
        null|Locale $locale = null,
    ): string {
        return Internal\format($this->getTimestamp(), $pattern, $timezone, $locale);
    }

    /**
     * Formats this {@see TemporalInterface} instance to a string based on the RFC 3339 format, with additional
     * options for second fractions and timezone representation.
     *
     * The RFC 3339 format is widely adopted in web and network protocols for its unambiguous representation of date, time,
     * and timezone information. This method not only ensures universal readability but also the precise specification
     * of time across various systems, being compliant with both RFC 3339 and ISO 8601 standards.
     *
     * Example usage:
     *
     * ```php
     * // Default formatting
     * $rfc_formatted_string = $datetime->toRfc3339();
     * // Customized formatting with milliseconds and 'Z' for UTC
     * $rfc_formatted_string_with_milliseconds_and_z = $datetime->toRfc3339(SecondsStyle::Milliseconds, true);
     * ```
     *
     * @param null|SecondsStyle $secondsStyle Optional parameter to specify the seconds formatting style. Automatically
     *                                         selected based on precision if null.
     * @param bool $useZ Determines the representation of UTC timezone. True to use 'Z', false to use the standard offset format.
     *
     * @return string The formatted string of the {@see TemporalInterface} instance, adhering to the RFC 3339 and compatible with ISO 8601 formats.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3339
     *
     * @psalm-mutation-free
     */
    public function toRfc3339(null|SecondsStyle $secondsStyle = null, bool $useZ = false): string
    {
        return Internal\format_rfc3339($this->getTimestamp(), $secondsStyle, $useZ);
    }

    /**
     * Provides a string representation of this {@see TemporalInterface} instance, formatted according to specified styles for date and time,
     * and optionally adjusted for a specific timezone and locale.
     *
     * This method offers a higher-level abstraction for formatting, allowing users to specify styles for date and time separately
     * rather than a custom pattern. If no styles are provided, default styles will be used.
     *
     * Additionally, the timezone and locale can be specified for locale-sensitive formatting.
     *
     * Example usage:
     *
     * ```php
     * $stringRepresentation = $temporal->toString(DateStyle::Long, TimeStyle::Short, $timezone, $locale);
     * ```
     *
     * @param null|DateStyle $dateStyle Optional style for the date portion of the output. If null, a default style is used.
     * @param null|TimeStyle $timeStyle Optional style for the time portion of the output. If null, a default style is used.
     * @param null|Timezone $timezone Optional timezone for formatting. If null, uses the system's default timezone.
     * @param null|Locale $locale Optional locale for formatting. If null, uses the system's default locale.
     *
     * @return string The string representation of the date and time, formatted according to the specified styles, timezone, and locale.
     *
     * @see DateStyle::default()
     * @see TimeStyle::default()
     *
     * @psalm-mutation-free
     */
    public function toString(
        null|DateStyle $dateStyle = null,
        null|TimeStyle $timeStyle = null,
        null|Timezone $timezone = null,
        null|Locale $locale = null,
    ): string {
        $timestamp = $this->getTimestamp();
        return Internal\create_intl_date_formatter($dateStyle, $timeStyle, null, $timezone, $locale)->format(
            $timestamp->getSeconds() + ($timestamp->getNanoseconds() / namespace\NANOSECONDS_PER_SECOND),
        );
    }

    /**
     * Magic method that provides a default string representation of the date and time.
     *
     * This method is a shortcut for calling `toString()` with all null arguments, returning a string formatted
     * with default styles, timezone, and locale. It is automatically called when the object is used in a string context.
     *
     * Example usage:
     *
     * ```php
     * $default_string_representation = (string) $temporal; // Uses __toString() for formatting
     * ```
     *
     * @return string The default string representation of the date and time.
     *
     * @see TemporalInterface::toString()
     *
     * @psalm-mutation-free
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
