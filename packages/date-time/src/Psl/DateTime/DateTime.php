<?php

declare(strict_types=1);

namespace Psl\DateTime;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use IntlTimeZone;
use Override;
use Psl\Interoperability;
use Psl\Locale\Locale;

use function intdiv;
use function sprintf;

/**
 * @psalm-immutable
 *
 * @implements Interoperability\ToStdlib<DateTimeImmutable>
 * @implements Interoperability\FromStdlib<DateTimeImmutable>
 * @implements Interoperability\ToIntl<IntlCalendar>
 * @implements Interoperability\FromIntl<IntlCalendar>
 *
 * @api
 */
final readonly class DateTime implements
    DateTimeInterface,
    Interoperability\ToStdlib,
    Interoperability\FromStdlib,
    Interoperability\ToIntl,
    Interoperability\FromIntl
{
    use DateTimeConvenienceMethodsTrait;

    private Timezone $timezone;

    private Timestamp $timestamp;

    private int $year;

    /**
     * @var int<1, 12>
     */
    private int $month;

    /**
     * @var int<1, 31>
     */
    private int $day;

    /**
     * @var int<0, 23>
     */
    private int $hours;

    /**
     * @var int<0, 59>
     */
    private int $minutes;

    /**
     * @var int<0, 59>
     */
    private int $seconds;

    /**
     * @var int<0, 999999999>
     */
    private int $nanoseconds;

    /**
     * Constructs a new date-time instance with specified components and timezone.
     *
     * This constructor initializes a date-time object with the provided year, month, day, hour, minute,
     * second, and nanosecond components within the given timezone. It ensures that all components are within their
     * valid ranges: nanoseconds [0, 999,999,999], seconds [0, 59], minutes [0, 59], hours [0, 23], month [1, 12],
     * and day [1, 28-31] depending on the month and leap year status. The constructor validates these components and
     * assigns them to the instance if they are valid. If any component is out of its valid range, an
     * `Exception\InvalidDateTimeException` is thrown.
     *
     * @throws Exception\InvalidArgumentException If any of the date or time components are outside their valid ranges,
     *                                            indicating an invalid date-time configuration.
     *
     * @psalm-mutation-free
     */
    private function __construct(
        Timezone $timezone,
        Timestamp $timestamp,
        int $year,
        int $month,
        int $day,
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
    ) {
        // @codeCoverageIgnoreStart
        if ($nanoseconds < 0 || $nanoseconds >= namespace\NANOSECONDS_PER_SECOND) {
            throw Exception\InvalidArgumentException::forNanoseconds($nanoseconds);
        }

        if ($seconds < 0 || $seconds >= 60) {
            throw Exception\InvalidArgumentException::forSeconds($seconds);
        }

        if ($minutes < 0 || $minutes >= 60) {
            throw Exception\InvalidArgumentException::forMinutes($minutes);
        }

        if ($hours < 0 || $hours >= 24) {
            throw Exception\InvalidArgumentException::forHours($hours);
        }

        if ($month < 1 || $month > 12) {
            throw Exception\InvalidArgumentException::forMonth($month);
        }

        // The exact length of the month is not checked here: callers pass fields computed by ICU, whose calendar
        // is Julian before 1582-10-15 and so has leap days (e.g. 1500-02-29) that the Gregorian rules lack.
        if ($day < 1 || $day > 31) {
            throw Exception\InvalidArgumentException::forDay($day, $month, $year);
        }

        // @codeCoverageIgnoreEnd

        $this->timestamp = $timestamp;
        $this->timezone = $timezone;
        $this->year = $year;
        $this->month = $month;
        $this->day = $day;
        $this->hours = $hours;
        $this->minutes = $minutes;
        $this->seconds = $seconds;
        $this->nanoseconds = $nanoseconds;
    }

    /**
     * Creates a new {@see DateTime} instance representing the current moment.
     *
     * This static method returns a {@see DateTime} object set to the current date and time. If a specific timezone is
     * provided, the returned {@see DateTime} will be adjusted to reflect the date and time in that timezone.
     *
     * @param null|Timezone $timezone Optional timezone. If null, uses the system's default timezone.
     *
     * @psalm-mutation-free
     */
    public static function now(null|Timezone $timezone = null): DateTime
    {
        return self::fromTimestamp(Timestamp::now(), $timezone);
    }

    /**
     * Creates a DateTime instance for a specific time on the current day within the specified timezone.
     *
     * This method facilitates the creation of a {@see DateTime} object representing a precise time on today's date. It is
     * particularly useful when you need to set a specific time of day for the current date in a given timezone. The
     * method combines the current date context with a specific time, offering a convenient way to specify times such
     * as "today at 14:00" in code.
     *
     * The time components (hours, minutes, seconds, nanoseconds) must be within their valid ranges. The method
     * enforces these constraints and throws an {@see Exception\InvalidArgumentException} if any component is out of bounds.
     *
     * @param int<0, 23> $hours The hour component of the time, ranging from 0 to 23.
     * @param int<0, 59> $minutes The minute component of the time, ranging from 0 to 59.
     * @param int<0, 59> $seconds The second component of the time, defaulting to 0, and ranging from 0 to 59.
     * @param int<0, 999999999> $nanoseconds The nanosecond component of the time, defaulting to 0, and ranging from 0 to 999,999,999.
     *
     * @throws Exception\UnexpectedValueException If any of the provided time components do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    public static function todayAt(
        int $hours,
        int $minutes,
        int $seconds = 0,
        int $nanoseconds = 0,
        null|Timezone $timezone = null,
    ): DateTime {
        return self::now($timezone)->withTime($hours, $minutes, $seconds, $nanoseconds);
    }

    /**
     * Creates a {@see DateTime} instance from individual date and time components.
     *
     * This method constructs a DateTime object using the specified year, month, day, hour, minute, second,
     * and nanosecond components within a given timezone. It validates each component against the Gregorian calendar
     * to ensure the date and time are possible. For example, it checks for the correct range of months (1-12),
     * days in a month (considering leap years), hours (0-23), minutes (0-59), and seconds (0-59).
     *
     * Note: In cases where the specified time occurs twice (such as during the end of daylight saving time), the later occurrence
     * is returned. To obtain the earlier occurrence, you can adjust the returned instance using `->minusHours(1)`.
     *
     * @param Month|int<1, 12> $month
     * @param int<1, 31> $day
     * @param int<0, 23> $hours
     * @param int<0, 59> $minutes
     * @param int<0, 59> $seconds
     * @param int<0, 999999999> $nanoseconds
     *
     * @throws Exception\UnexpectedValueException If any of the provided date or time components do not align with calendar expectations.
     * @throws Exception\OverflowException If the year is greater than 5368709, the latest supported year.
     * @throws Exception\UnderflowException If the year is less than -5838268, the earliest supported year.
     *
     * @pure
     */
    public static function fromParts(
        Timezone $timezone,
        int $year,
        Month|int $month,
        int $day,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $nanoseconds = 0,
    ): self {
        if ($month instanceof Month) {
            $month = $month->value;
        }

        $calendar = Internal\create_intl_calendar_from_date_time(
            $timezone,
            $year,
            $month,
            $day,
            $hours,
            $minutes,
            $seconds,
        );

        if ($seconds !== $calendar->get(IntlCalendar::FIELD_SECOND)) {
            throw Exception\UnexpectedValueException::forSeconds($seconds, $calendar->get(IntlCalendar::FIELD_SECOND));
        }

        if ($minutes !== $calendar->get(IntlCalendar::FIELD_MINUTE)) {
            throw Exception\UnexpectedValueException::forMinutes($minutes, $calendar->get(IntlCalendar::FIELD_MINUTE));
        }

        if ($hours !== $calendar->get(IntlCalendar::FIELD_HOUR_OF_DAY)) {
            throw Exception\UnexpectedValueException::forHours($hours, $calendar->get(IntlCalendar::FIELD_HOUR_OF_DAY));
        }

        if ($day !== $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH)) {
            throw Exception\UnexpectedValueException::forDay($day, $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH));
        }

        if ($month !== ($calendar->get(IntlCalendar::FIELD_MONTH) + 1)) {
            throw Exception\UnexpectedValueException::forMonth($month, $calendar->get(IntlCalendar::FIELD_MONTH) + 1);
        }

        if ($year !== $calendar->get(IntlCalendar::FIELD_EXTENDED_YEAR)) {
            throw Exception\UnexpectedValueException::forYear($year, $calendar->get(IntlCalendar::FIELD_EXTENDED_YEAR));
        }

        $timestampInSeconds = (int) ($calendar->getTime() / (float) namespace\MILLISECONDS_PER_SECOND);
        $timestamp = Timestamp::fromParts($timestampInSeconds, $nanoseconds);

        return new self($timezone, $timestamp, $year, $month, $day, $hours, $minutes, $seconds, $nanoseconds);
    }

    /**
     * Creates a {@see DateTime} instance from a timestamp, representing the same point in time.
     *
     * This method converts a {@see Timestamp} into a {@see DateTime} instance calculated for the specified timezone.
     *
     * @param null|Timezone $timezone Optional timezone. If null, uses the system's default timezone.
     *
     * @see Timezone::default()
     *
     * @psalm-mutation-free
     */
    #[Override]
    public static function fromTimestamp(Timestamp $timestamp, null|Timezone $timezone = null): static
    {
        $timezone ??= Timezone::default();

        $calendar = Internal\create_intl_calendar($timezone);

        $calendar->setTime($timestamp->getSeconds() * namespace\MILLISECONDS_PER_SECOND);

        $year = $calendar->get(IntlCalendar::FIELD_EXTENDED_YEAR);
        $month = $calendar->get(IntlCalendar::FIELD_MONTH) + 1;
        $day = $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH);
        $hour = $calendar->get(IntlCalendar::FIELD_HOUR_OF_DAY);
        $minute = $calendar->get(IntlCalendar::FIELD_MINUTE);
        $second = $calendar->get(IntlCalendar::FIELD_SECOND);
        $nanoseconds = $timestamp->getNanoseconds();

        return new static($timezone, $timestamp, $year, $month, $day, $hour, $minute, $second, $nanoseconds);
    }

    /**
     * Parses a date and time string into an instance of {@see DateTime} using a specific format pattern, with optional customization for timezone and locale.
     *
     * This method is specifically designed for cases where a custom format pattern is used to parse the input string.
     *
     * It allows for precise control over the parsing process by specifying the exact format pattern that matches the input string.
     *
     * Additionally, the method supports specifying a timezone and locale for parsing, enabling accurate interpretation of locale-specific formats.
     *
     * Example usage:
     *
     * ```php
     * $rawString = '2023-03-15 12:00:00';
     * $parsed_datetime = DateTime\DateTime::parse($rawString, 'yyyy-MM-dd HH:mm:ss', DateTime\Timezone::UTC, Locale\Locale::English);
     * ```
     *
     * @param string $rawString The date and time string to parse.
     * @param null|FormatPattern|string $pattern The custom format pattern for parsing the date and time. If null, uses a default pattern.
     * @param null|Timezone $timezone Optional timezone for parsing. If null, uses the system's default timezone.
     * @param null|Locale $locale Optional locale for parsing. If null, uses the system's default locale.
     *
     * @throws Exception\RuntimeException If the parsing process fails.
     *
     * @return static Returns an instance of {@see DateTime} representing the parsed date and time.
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
        $timezone ??= Timezone::default();

        return self::fromTimestamp(Timestamp::parse($rawString, $pattern, $timezone, $locale), $timezone);
    }

    /**
     * Creates an instance of {@see DateTime} from a date and time string, formatted according to specified styles for date and time,
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
     * $datetime = DateTime\DateTime::fromString($rawString, DateTime\DateStyle::Long, DateTime\TimeStyle::Short, DateTime\Timezone::UTC, Locale\Locale::English);
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
     * @return static Returns an instance of {@see DateTime} representing the parsed date and time.
     *
     * @see DateTimeInterface::toString()
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
        $timezone ??= Timezone::default();

        return self::fromTimestamp(
            Timestamp::fromString($rawString, $dateStyle, $timeStyle, $timezone, $locale),
            $timezone,
        );
    }

    /**
     * Returns the timestamp representation of this date time object.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getTimestamp(): Timestamp
    {
        return $this->timestamp;
    }

    /**
     * Retrieves the year as an integer, following ISO-8601 conventions for numbering.
     *
     * This method returns the year part of the date. For years in the Anno Domini (AD) era, the returned value matches
     * the Gregorian calendar year directly (e.g., 1 for AD 1, 2021 for AD 2021, etc.). For years before AD 1, the method
     * adheres to the ISO-8601 standard, which uses astronomical year numbering: 1 BC is represented as 0, 2 BC as -1, 3 BC as -2,
     * and so forth. This ISO-8601 numbering facilitates straightforward mathematical operations on years across the AD/BC
     * divide but may require conversion for user-friendly display or when interfacing with systems that use the traditional
     * AD/BC notation.
     *
     * @return int The year, formatted according to ISO-8601 standards, where 1 AD is 1, 1 BC is 0, 2 BC is -1, etc.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getYear(): int
    {
        return $this->year;
    }

    /**
     * Returns the month.
     *
     * @return int<1, 12>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getMonth(): int
    {
        return $this->month;
    }

    /**
     * Returns the day.
     *
     * @return int<1, 31>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getDay(): int
    {
        return $this->day;
    }

    /**
     * Returns the hours.
     *
     * @return int<0, 23>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getHours(): int
    {
        return $this->hours;
    }

    /**
     * Returns the minutes.
     *
     * @return int<0, 59>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getMinutes(): int
    {
        return $this->minutes;
    }

    /**
     * Returns the seconds.
     *
     * @return int<0, 59>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getSeconds(): int
    {
        return $this->seconds;
    }

    /**
     * Returns the nanoseconds.
     *
     * @return int<0, 999999999>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getNanoseconds(): int
    {
        return $this->nanoseconds;
    }

    /**
     * Gets the timezone associated with the date and time.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getTimezone(): Timezone
    {
        return $this->timezone;
    }

    /**
     * Returns a new instance with the specified date.
     *
     * @param Month|int<1, 12> $month
     * @param int<1, 31> $day
     *
     * @throws Exception\UnexpectedValueException If any of the provided date components do not align with calendar expectations.
     * @throws Exception\OverflowException If the year is greater than 5368709, the latest supported year.
     * @throws Exception\UnderflowException If the year is less than -5838268, the earliest supported year.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withDate(int $year, Month|int $month, int $day): static
    {
        return static::fromParts(
            $this->getTimezone(),
            $year,
            $month,
            $day,
            $this->getHours(),
            $this->getMinutes(),
            $this->getSeconds(),
            $this->getNanoseconds(),
        );
    }

    /**
     * Returns a new instance with the specified time.
     *
     * @param int<0, 23> $hours
     * @param int<0, 59> $minutes
     * @param int<0, 59> $seconds
     * @param int<0, 999999999> $nanoseconds
     *
     * @throws Exception\UnexpectedValueException If any of the provided time components do not align with calendar expectations.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function withTime(int $hours, int $minutes, int $seconds = 0, int $nanoseconds = 0): static
    {
        return static::fromParts(
            $this->getTimezone(),
            $this->getYear(),
            $this->getMonth(),
            $this->getDay(),
            $hours,
            $minutes,
            $seconds,
            $nanoseconds,
        );
    }

    /**
     * Creates a {@see DateTime} instance from a PHP {@see DateTimeImmutable}.
     *
     * @param DateTimeImmutable $value
     *
     * @throws Exception\InvalidArgumentException If the timezone has no {@see Timezone} equivalent.
     *
     * @psalm-mutation-free
     *
     * @see Timezone::fromStdlib()
     */
    #[Override]
    public static function fromStdlib(mixed $value): static
    {
        $timezone = Timezone::fromStdlib($value->getTimezone());
        $seconds = $value->getTimestamp();
        $microseconds = (int) $value->format('u');
        $nanoseconds = $microseconds * namespace\NANOSECONDS_PER_MICROSECOND;

        return self::fromTimestamp(Timestamp::fromParts($seconds, $nanoseconds), $timezone);
    }

    /**
     * Converts this {@see DateTime} to a PHP {@see DateTimeImmutable} representing the same instant
     * in the same timezone.
     *
     * Note: nanosecond precision is truncated to microseconds.
     *
     * Note: PHP uses the proleptic Gregorian calendar, while this class follows ICU and uses the Julian
     * calendar before 1582-10-15, so the date fields of instants before that day differ between the two.
     *
     * @return DateTimeImmutable
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toStdlib(): mixed
    {
        // Convert the instant, not the wall-clock fields: re-parsing the fields picks one occurrence of an
        // ambiguous local time, and cannot express negative years or Julian dates.
        $value = DateTimeImmutable::createFromFormat('U.u', sprintf(
            '%d.%06d',
            $this->timestamp->getSeconds(),
            intdiv($this->nanoseconds, namespace\NANOSECONDS_PER_MICROSECOND),
        ));

        // @codeCoverageIgnoreStart
        if (false === $value) {
            throw new Exception\RuntimeException('Failed to convert the date-time to a DateTimeImmutable instance.');
        }

        // @codeCoverageIgnoreEnd

        return $value->setTimezone(new DateTimeZone($this->timezone->value));
    }

    /**
     * Creates a {@see DateTime} instance from an {@see IntlCalendar}.
     *
     * @param IntlCalendar $value
     *
     * @throws Exception\InvalidArgumentException If the timezone has no {@see Timezone} equivalent.
     *
     * @psalm-mutation-free
     *
     * @see Timezone::fromIntl()
     */
    #[Override]
    public static function fromIntl(mixed $value): static
    {
        /** @var IntlTimeZone $intlTz */
        $intlTz = $value->getTimeZone();
        $timezone = Timezone::fromIntl($intlTz);
        $millis = $value->getTime();
        $seconds = (int) ($millis / namespace\MILLISECONDS_PER_SECOND);
        $remainingMillis = $millis % namespace\MILLISECONDS_PER_SECOND;
        $nanoseconds = (int) ($remainingMillis * namespace\NANOSECONDS_PER_MILLISECOND);

        return self::fromTimestamp(Timestamp::fromParts($seconds, $nanoseconds), $timezone);
    }

    /**
     * Converts this {@see DateTime} to an {@see IntlCalendar}.
     *
     * Note: nanosecond precision is truncated to milliseconds.
     *
     * @return IntlCalendar
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toIntl(): mixed
    {
        // Convert the instant, not the wall-clock fields, which are ambiguous during a DST overlap.
        $calendar = Internal\create_intl_calendar($this->timezone);
        $calendar->setTime(
            ($this->timestamp->getSeconds() * namespace\MILLISECONDS_PER_SECOND)
            + intdiv($this->nanoseconds, namespace\NANOSECONDS_PER_MILLISECOND),
        );

        return $calendar;
    }

    /**
     * @psalm-mutation-free
     *
     * @return array{
     *     timezone: Timezone,
     *     timestamp: Timestamp,
     *     year: int,
     *     month: int<1, 12>,
     *     day: int<1, 31>,
     *     hours: int<0, 23>,
     *     minutes: int<0, 59>,
     *     seconds: int<0, 59>,
     *     nanoseconds: int<0, 999999999>,
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'timezone' => $this->timezone,
            'timestamp' => $this->timestamp,
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'hours' => $this->hours,
            'minutes' => $this->minutes,
            'seconds' => $this->seconds,
            'nanoseconds' => $this->nanoseconds,
        ];
    }
}
