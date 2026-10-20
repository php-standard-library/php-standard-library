<?php

declare(strict_types=1);

namespace Psl\DateTime;

use Override;
use Psl\Locale\Locale;

use function ceil;
use function intdiv;
use function max;
use function min;

/**
 * @require-implements DateTimeInterface
 *
 * @psalm-immutable
 *
 * @api
 */
trait DateTimeConvenienceMethodsTrait
{
    use TemporalConvenienceMethodsTrait {
        toRfc3339 as private toRfc3339Impl;
    }

    /**
     * Checks if this {@see DateTimeInterface} instance is equal to the given {@see DateTimeInterface} instance including the timezone.
     *
     * @param DateTimeInterface $other The {@see DateTimeInterface} instance to compare with.
     *
     * @return bool True if equal including timezone, false otherwise.
     *
     * @psalm-mutation-free
     */
    public function equalsIncludingTimezone(DateTimeInterface $other): bool
    {
        return $this->equals($other) && $this->getTimezone() === $other->getTimezone();
    }

    /**
     * Obtains the timezone offset as a {@see Duration} object.
     *
     * This method effectively returns the offset from UTC for the timezone of this instance at the specific date and time it represents.
     *
     * It is equivalent to executing `$dt->getTimezone()->getOffset($dt)`, which calculates the offset for the timezone of this instance.
     *
     * @return Duration The offset from UTC as a Duration.
     *
     * @psalm-mutation-free
     */
    public function getTimezoneOffset(): Duration
    {
        return $this->getTimezone()->getOffset($this);
    }

    /**
     * Determines whether this instance is currently in daylight saving time.
     *
     * This method checks if the date and time represented by this instance fall within the daylight saving time period of its timezone.
     *
     * It is equivalent to `!$dt->getTimezone()->getDaylightSavingTimeOffset($dt)->isZero()`, indicating whether there is a non-zero DST offset.
     *
     * @return bool True if in daylight saving time, false otherwise.
     *
     * @psalm-mutation-free
     */
    public function isDaylightSavingTime(): bool
    {
        return !$this->getTimezone()->getDaylightSavingTimeOffset($this)->isZero();
    }

    /**
     * Converts the {@see DateTimeInterface} instance to the specified timezone.
     *
     * @param Timezone $timezone The timezone to convert to.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function convertToTimezone(Timezone $timezone): static
    {
        return static::fromTimestamp($this->getTimestamp(), $timezone);
    }

    /**
     * Returns a new instance with the specified year.
     *
     * @throws Exception\UnexpectedValueException If the provided year do not align with calendar expectations.
     * @throws Exception\OverflowException If the year is greater than 5368709, the latest supported year.
     * @throws Exception\UnderflowException If the year is less than -5838268, the earliest supported year.
     *
     * @psalm-mutation-free
     */
    public function withYear(int $year): static
    {
        return $this->withDate($year, $this->getMonth(), $this->getDay());
    }

    /**
     * Returns a new instance with the specified month.
     *
     * @param Month|int<1, 12> $month
     *
     * @throws Exception\UnexpectedValueException If the provided month do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public function withMonth(Month|int $month): static
    {
        return $this->withDate($this->getYear(), $month, $this->getDay());
    }

    /**
     * Returns a new instance with the specified day.
     *
     * @param int<1, 31> $day
     *
     * @throws Exception\UnexpectedValueException If the provided day do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public function withDay(int $day): static
    {
        return $this->withDate($this->getYear(), $this->getMonth(), $day);
    }

    /**
     * Returns a new instance with the specified hours.
     *
     * @param int<0, 23> $hours
     *
     * @throws Exception\UnexpectedValueException If the provided hours do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public function withHours(int $hours): static
    {
        return $this->withTime($hours, $this->getMinutes(), $this->getSeconds(), $this->getNanoseconds());
    }

    /**
     * Returns a new instance with the specified minutes.
     *
     * @param int<0, 59> $minutes
     *
     * @throws Exception\UnexpectedValueException If the provided minutes do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public function withMinutes(int $minutes): static
    {
        return $this->withTime($this->getHours(), $minutes, $this->getSeconds(), $this->getNanoseconds());
    }

    /**
     * Returns a new instance with the specified seconds.
     *
     * @param int<0, 59> $seconds
     *
     * @throws Exception\UnexpectedValueException If the provided seconds do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public function withSeconds(int $seconds): static
    {
        return $this->withTime($this->getHours(), $this->getMinutes(), $seconds, $this->getNanoseconds());
    }

    /**
     * Returns a new instance with the specified nanoseconds.
     *
     * @param int<0, 999999999> $nanoseconds
     *
     * @throws Exception\UnexpectedValueException If the provided nanoseconds do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public function withNanoseconds(int $nanoseconds): static
    {
        return $this->withTime($this->getHours(), $this->getMinutes(), $this->getSeconds(), $nanoseconds);
    }

    /**
     * Returns a new instance representing the start of the same day (00:00:00.000000000).
     *
     * @psalm-mutation-free
     */
    public function atStartOfDay(): static
    {
        return $this->withTime(0, 0, 0, 0);
    }

    /**
     * Returns a new instance representing the end of the same day (23:59:59.999999999).
     *
     * @psalm-mutation-free
     */
    public function atEndOfDay(): static
    {
        return $this->withTime(23, 59, 59, 999_999_999);
    }

    /**
     * Returns a new instance representing the start of the current month (1st day at 00:00:00.000000000).
     *
     * @psalm-mutation-free
     */
    public function atStartOfMonth(): static
    {
        return $this->withDate($this->getYear(), $this->getMonth(), 1)->withTime(0, 0, 0, 0);
    }

    /**
     * Returns a new instance representing the end of the current month (last day at 23:59:59.999999999).
     *
     * @psalm-mutation-free
     */
    public function atEndOfMonth(): static
    {
        $monthEnum = Month::from($this->getMonth());
        $lastDay = $monthEnum->getDaysForYear($this->getYear());

        return $this->withDate($this->getYear(), $this->getMonth(), $lastDay)->withTime(23, 59, 59, 999_999_999);
    }

    /**
     * Returns a new instance representing the start of the current year (January 1st at 00:00:00.000000000).
     *
     * @psalm-mutation-free
     */
    public function atStartOfYear(): static
    {
        return $this->withDate($this->getYear(), 1, 1)->withTime(0, 0, 0, 0);
    }

    /**
     * Returns a new instance representing the end of the current year (December 31st at 23:59:59.999999999).
     *
     * @psalm-mutation-free
     */
    public function atEndOfYear(): static
    {
        return $this->withDate($this->getYear(), 12, 31)->withTime(23, 59, 59, 999_999_999);
    }

    /**
     * Returns a new instance representing the start of the current ISO week (Monday at 00:00:00.000000000).
     *
     * @throws Exception\UnderflowException If the operation results in an arithmetic underflow.
     * @throws Exception\OverflowException If the operation results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function atStartOfWeek(): static
    {
        $daysBack = $this->getWeekday()->value - Weekday::Monday->value;

        return $this->minusDays($daysBack)->atStartOfDay();
    }

    /**
     * Returns a new instance representing the end of the current ISO week (Sunday at 23:59:59.999999999).
     *
     * @throws Exception\UnderflowException If the operation results in an arithmetic underflow.
     * @throws Exception\OverflowException If the operation results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function atEndOfWeek(): static
    {
        $daysForward = Weekday::Sunday->value - $this->getWeekday()->value;

        return $this->plusDays($daysForward)->atEndOfDay();
    }

    /**
     * Returns the date (year, month, day).
     *
     * @return array{int, int<1, 12>, int<1, 31>} The date.
     *
     * @psalm-mutation-free
     */
    public function getDate(): array
    {
        return [$this->getYear(), $this->getMonth(), $this->getDay()];
    }

    /**
     * Returns the time (hours, minutes, seconds, nanoseconds).
     *
     * @return array{
     *     int<0, 23>,
     *     int<0, 59>,
     *     int<0, 59>,
     *     int<0, 999999999>,
     * }
     *
     * @psalm-mutation-free
     */
    public function getTime(): array
    {
        return [
            $this->getHours(),
            $this->getMinutes(),
            $this->getSeconds(),
            $this->getNanoseconds(),
        ];
    }

    /**
     * Returns the {@see DateTimeInterface} parts (year, month, day, hours, minutes, seconds, nanoseconds).
     *
     * @return array{
     *     int,
     *     int<1, 12>,
     *     int<1, 31>,
     *     int<0, 23>,
     *     int<0, 59>,
     *     int<0, 59>,
     *     int<0, 999999999>,
     * }
     *
     * @psalm-mutation-free
     */
    public function getParts(): array
    {
        return [
            $this->getYear(),
            $this->getMonth(),
            $this->getDay(),
            $this->getHours(),
            $this->getMinutes(),
            $this->getSeconds(),
            $this->getNanoseconds(),
        ];
    }

    /**
     * Retrieves the era of the date represented by this DateTime instance.
     *
     * This method returns an instance of the `Era` enum, which indicates whether the date
     * falls in the Anno Domini (AD) or Before Christ (BC) era. The era is determined based on the
     * astronomical year returned by {@see DateTimeInterface::getYear()}: years greater than zero are AD,
     * while zero (1 BC) and negative years (2 BC, 3 BC, ...) are BC.
     *
     * @psalm-mutation-free
     */
    public function getEra(): Era
    {
        return Era::fromYear($this->getYear());
    }

    /**
     * Returns the century number for the year stored in this object.
     *
     * @psalm-mutation-free
     */
    public function getCentury(): int
    {
        return (int) ceil($this->getYear() / 100);
    }

    /**
     * Returns the short format of the year (last 2 digits).
     *
     * @return int<-99, 99> The short format of the year.
     *
     * @psalm-mutation-free
     */
    public function getYearShort(): int
    {
        return $this->getYear() % 100;
    }

    /**
     * Returns the month as an instance of the {@see Month} enum.
     *
     * This method converts the numeric representation of the month into its corresponding
     * case in the {@see Month} enum, providing a type-safe way to work with months.
     *
     * @return Month The month as an enum case.
     *
     * @psalm-mutation-free
     */
    public function getMonthEnum(): Month
    {
        return Month::from($this->getMonth());
    }

    /**
     * Returns the hours using the 12-hour format (1 to 12) along with the meridiem indicator.
     *
     * @return array{int<1, 12>, Meridiem} The hours and meridiem indicator.
     *
     * @psalm-mutation-free
     */
    public function getTwelveHours(): array
    {
        $hours = $this->getHours();
        $twelveHours = $hours % 12;
        if (0 === $twelveHours) {
            $twelveHours = 12;
        }

        return [$twelveHours, $hours < 12 ? Meridiem::AnteMeridiem : Meridiem::PostMeridiem];
    }

    /**
     * Retrieves the ISO-8601 year and week number corresponding to the date.
     *
     * This method returns an array consisting of two integers: the first represents the year, and the second
     * represents the week number according to ISO-8601 standards, which ranges from 1 to 53. The week numbering
     * follows the ISO-8601 specification, where a week starts on a Monday and the first week of the year is the
     * one that contains at least four days of the new year.
     *
     * Due to the ISO-8601 week numbering rules, the returned year might not always match the Gregorian year
     * obtained from `$this->getYear()`. Specifically:
     *
     *  - The first few days of January might belong to the last week of the previous year if they fall before
     *      the first Thursday of January.
     *
     *  - Conversely, the last days of December might be part of the first week of the following year if they
     *      extend beyond the last Thursday of December.
     *
     * Examples:
     *  - For the date 2020-01-01, it returns [2020, 1], indicating the first week of 2020.
     *  - For the date 2021-01-01, it returns [2020, 53], showing that this day is part of the last week of 2020
     *      according to ISO-8601.
     *
     * @return array{int, int<1, 53>}
     *
     * @psalm-mutation-free
     */
    public function getISOWeekNumber(): array
    {
        /** @var int<1, 53> $week */
        $week = (int) $this->format(pattern: 'w', locale: Locale::EnglishUnitedKingdom);

        $year = (int) $this->format(pattern: 'Y', locale: Locale::EnglishUnitedKingdom);

        return [$year, $week];
    }

    /**
     * Gets the weekday of the date.
     *
     * @return Weekday The weekday.
     *
     * @psalm-mutation-free
     */
    public function getWeekday(): Weekday
    {
        return Weekday::from((int) $this->format(pattern: 'e', locale: Locale::EnglishUnitedKingdom));
    }

    /**
     * Returns the day of the year (1–366).
     *
     * @return int<1, 366>
     *
     * @psalm-mutation-free
     */
    public function getDayOfYear(): int
    {
        /** @var int<1, 366> */
        return (int) $this->format(pattern: 'D', locale: Locale::EnglishUnitedKingdom);
    }

    /**
     * Checks if the year is a leap year.
     *
     * @psalm-mutation-free
     */
    public function isLeapYear(): bool
    {
        return namespace\is_leap_year($this->getYear());
    }

    /**
     * Adds the specified years to this date-time object, returning a new instance with the added years.
     *
     * The day is clamped to the length of the resulting month (February 29 plus one year is February 28),
     * and the wall-clock time is kept. See {@see DateTimeConvenienceMethodsTrait::plusDays()} for how a
     * wall-clock time that does not exist, or exists twice, on the resulting day is resolved.
     *
     * @throws Exception\UnderflowException If adding the years results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the years results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusYears(int $years): static
    {
        return $this->shiftLocalDateTime(1, $years, 0, 0, 0);
    }

    /**
     * Subtracts the specified years from this date-time object, returning a new instance with the subtracted years.
     *
     * The day is clamped to the length of the resulting month, and the wall-clock time is kept.
     * See {@see DateTimeConvenienceMethodsTrait::plusYears()}.
     *
     * @throws Exception\UnderflowException If subtracting the years results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the years results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusYears(int $years): static
    {
        return $this->shiftLocalDateTime(-1, $years, 0, 0, 0);
    }

    /**
     * Adds the specified months to this date-time object, returning a new instance with the added months.
     *
     * The day is clamped to the length of the resulting month (January 31 plus one month is the last day
     * of February), and the wall-clock time is kept. See {@see DateTimeConvenienceMethodsTrait::plusDays()}
     * for how a wall-clock time that does not exist, or exists twice, on the resulting day is resolved.
     *
     * @throws Exception\UnderflowException If adding the months results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the months results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusMonths(int $months): static
    {
        return $this->shiftLocalDateTime(1, 0, $months, 0, 0);
    }

    /**
     * Subtracts the specified months from this date-time object, returning a new instance with the subtracted months.
     *
     * The day is clamped to the length of the resulting month, and the wall-clock time is kept.
     * See {@see DateTimeConvenienceMethodsTrait::plusMonths()}.
     *
     * @throws Exception\UnderflowException If subtracting the months results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the months results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusMonths(int $months): static
    {
        return $this->shiftLocalDateTime(-1, 0, $months, 0, 0);
    }

    /**
     * Adds the specified weeks to this date-time object, returning a new instance with the added weeks.
     *
     * This is calendar arithmetic: the wall-clock time is kept, even across daylight saving time
     * transitions. See {@see DateTimeConvenienceMethodsTrait::plusDays()}.
     *
     * @throws Exception\UnderflowException If adding the weeks results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the weeks results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusWeeks(int $weeks): static
    {
        return $this->shiftLocalDateTime(1, 0, 0, $weeks, 0);
    }

    /**
     * Subtracts the specified weeks from this date-time object, returning a new instance with the subtracted weeks.
     *
     * This is calendar arithmetic: the wall-clock time is kept, even across daylight saving time
     * transitions. See {@see DateTimeConvenienceMethodsTrait::plusDays()}.
     *
     * @throws Exception\UnderflowException If subtracting the weeks results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the weeks results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusWeeks(int $weeks): static
    {
        return $this->shiftLocalDateTime(-1, 0, 0, $weeks, 0);
    }

    /**
     * Adds the specified days to this date-time object, returning a new instance with the added days.
     *
     * This is calendar arithmetic, not a multiple of 24 hours: the date moves by the given number of days
     * and the wall-clock time is kept, even across daylight saving time transitions. For example, in
     * Europe/London, 2024-03-30 12:00 plus one day is 2024-03-31 12:00, which is only 23 hours later.
     *
     * If the wall-clock time does not exist on the resulting day (it falls in a forward transition), it is
     * moved forward by the length of the transition. If it exists twice, the occurrence with the current
     * UTC offset is used when possible, otherwise the later one.
     *
     * Use {@see DateTimeConvenienceMethodsTrait::plus()} with a {@see Duration} for exact elapsed time.
     *
     * @throws Exception\UnderflowException If adding the days results in an arithmetic underflow.
     * @throws Exception\OverflowException If adding the days results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plusDays(int $days): static
    {
        return $this->shiftLocalDateTime(1, 0, 0, 0, $days);
    }

    /**
     * Subtracts the specified days from this date-time object, returning a new instance with the subtracted days.
     *
     * This is calendar arithmetic: the wall-clock time is kept, even across daylight saving time
     * transitions. See {@see DateTimeConvenienceMethodsTrait::plusDays()}.
     *
     * @throws Exception\UnderflowException If subtracting the days results in an arithmetic underflow.
     * @throws Exception\OverflowException If subtracting the days results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minusDays(int $days): static
    {
        return $this->shiftLocalDateTime(-1, 0, 0, 0, $days);
    }

    /**
     * Adds the specified temporal amount to this date-time object, returning a new instance.
     *
     * Supports both {@see Duration} (exact time) and {@see Period} (calendar-aware arithmetic,
     * e.g. adding 1 month to January 31 yields February 28/29).
     *
     * @throws Exception\UnderflowException If the operation results in an arithmetic underflow.
     * @throws Exception\OverflowException If the operation results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function plus(TemporalAmountInterface $amount): static
    {
        if ($amount instanceof Duration) {
            return static::fromTimestamp($this->getTimestamp()->plus($amount), $this->getTimezone());
        }

        return $this->applyCalendarOffset($amount, 1);
    }

    /**
     * Subtracts the specified temporal amount from this date-time object, returning a new instance.
     *
     * Supports both {@see Duration} (exact time) and {@see Period} (calendar-aware arithmetic).
     *
     * @throws Exception\UnderflowException If the operation results in an arithmetic underflow.
     * @throws Exception\OverflowException If the operation results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    public function minus(TemporalAmountInterface $amount): static
    {
        if ($amount instanceof Duration) {
            return static::fromTimestamp($this->getTimestamp()->minus($amount), $this->getTimezone());
        }

        return $this->applyCalendarOffset($amount, -1);
    }

    /**
     * Applies a calendar-aware offset (Period) in a single pass.
     *
     * @param 1|-1 $sign 1 for addition, -1 for subtraction.
     *
     * @throws Exception\UnderflowException If the operation results in an arithmetic underflow.
     * @throws Exception\OverflowException If the operation results in an arithmetic overflow.
     *
     * @psalm-mutation-free
     */
    private function applyCalendarOffset(Period $period, int $sign): static
    {
        return $this->shiftLocalDateTime($sign, $period->getYears(), $period->getMonths(), 0, $period->getDays());
    }

    /**
     * Moves the local (wall-clock) date by `$sign` times the given years, months, weeks, and days, keeping
     * the wall-clock time, and resolves the result in this instance's timezone.
     *
     * Years and months are added first, clamping the day to the length of the resulting month (January 31
     * plus one month is the last day of February); weeks and days are added after that.
     *
     * @param 1|-1 $sign 1 for addition, -1 for subtraction.
     *
     * @throws Exception\UnderflowException If the resulting date is before -5838268-01-01, the earliest supported date.
     * @throws Exception\OverflowException If the resulting date is after 5368709-12-31, the latest supported date.
     *
     * @psalm-mutation-free
     */
    private function shiftLocalDateTime(int $sign, int $years, int $months, int $weeks, int $days): static
    {
        // Each of these limits is beyond the supported range (about 11.2 million years) on its own, so a
        // clamped amount still produces an out-of-range date, which is rejected; clamping keeps the
        // arithmetic below within the integer range.
        $years = max(-12_000_000, min(12_000_000, $years));
        $months = max(-144_000_000, min(144_000_000, $months));
        $weeks = max(-600_000_000, min(600_000_000, $weeks));
        $days = max(-4_200_000_000, min(4_200_000_000, $days));

        $months = $sign * (($years * namespace\MONTHS_PER_YEAR) + $months);
        $days = $sign * (($weeks * namespace\DAYS_PER_WEEK) + $days);
        if (0 === $months && 0 === $days) {
            return $this;
        }

        $year = $this->getYear();
        $month = $this->getMonth();
        $day = $this->getDay();
        if (0 !== $months) {
            $totalMonths = ($year * namespace\MONTHS_PER_YEAR) + $month - 1 + $months;
            $year = intdiv($totalMonths, namespace\MONTHS_PER_YEAR);
            $month = $totalMonths % namespace\MONTHS_PER_YEAR;
            // @codeCoverageIgnoreStart
            if ($month < 0) {
                $year--;
                $month += namespace\MONTHS_PER_YEAR;
            }

            // @codeCoverageIgnoreEnd

            // Reject an out-of-range year here: the amounts may have been clamped above, so the year itself
            // would be misleading in the error message.
            if ($year > 5_368_709) {
                throw new Exception\OverflowException(
                    'The resulting date is after 5368709-12-31, the latest supported date.',
                );
            }

            if ($year < -5_838_268) {
                throw new Exception\UnderflowException(
                    'The resulting date is before -5838268-01-01, the earliest supported date.',
                );
            }

            $month += 1;
            $day = min($day, Month::from($month)->getDaysForYear($year));
        }

        if (0 !== $days) {
            [$year, $month, $day] = Internal\add_days_to_date($year, $month, $day, $days);
        }

        // Prefer the UTC offset currently in effect when the wall-clock time happens twice on the new date.
        $timezone = $this->getTimezone();
        $timestamp = $this->getTimestamp();
        $seconds = Internal\resolve_local_date_time(
            $timezone,
            $year,
            $month,
            $day,
            $this->getHours(),
            $this->getMinutes(),
            $this->getSeconds(),
            $timestamp->getSeconds(),
        );

        return static::fromTimestamp(Timestamp::fromParts($seconds, $timestamp->getNanoseconds()), $timezone);
    }

    /**
     * Formats this {@see DateTimeInterface} instance based on a specific pattern, with optional customization for timezone and locale.
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
     * @param null|Timezone $timezone Optional timezone for formatting. If null, uses the current timezone.
     * @param null|Locale $locale Optional locale for formatting. If null, uses the system's default locale.
     *
     * @return string The formatted date and time string, according to the specified pattern, timezone, and locale.
     *
     * @see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
     * @see Locale::default()
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function format(
        null|FormatPattern|string $pattern = null,
        null|Timezone $timezone = null,
        null|Locale $locale = null,
    ): string {
        return Internal\format($this->getTimestamp(), $pattern, $timezone ?? $this->getTimezone(), $locale);
    }

    /**
     * Formats this {@see DateTimeInterface} instance to a string based on the RFC 3339 format, with additional
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
     * @return string The formatted string of the {@see DateTimeInterface} instance, adhering to the RFC 3339 and compatible with ISO 8601 formats.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3339
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toRfc3339(null|SecondsStyle $secondsStyle = null, bool $useZ = false): string
    {
        return Internal\format_rfc3339($this->getTimestamp(), $secondsStyle, $useZ, $this->getTimezone());
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
     * @param null|Timezone $timezone Optional timezone for formatting. If null, uses the current timezone.
     * @param null|Locale $locale Optional locale for formatting. If null, uses the system's default locale.
     *
     * @return string The string representation of the date and time, formatted according to the specified styles, timezone, and locale.
     *
     * @see DateStyle::default()
     * @see TimeStyle::default()
     * @see Locale::default()
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toString(
        null|DateStyle $dateStyle = null,
        null|TimeStyle $timeStyle = null,
        null|Timezone $timezone = null,
        null|Locale $locale = null,
    ): string {
        $timestamp = $this->getTimestamp();

        return Internal\create_intl_date_formatter(
            $dateStyle,
            $timeStyle,
            null,
            $timezone ?? $this->getTimezone(),
            $locale,
        )->format($timestamp->getSeconds());
    }
}
