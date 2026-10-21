<?php

declare(strict_types=1);

namespace Psl\DateTime\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\DateStyle;
use Psl\DateTime\DateTime;
use Psl\DateTime\Era;
use Psl\DateTime\Exception\InvalidArgumentException;
use Psl\DateTime\Exception\OverflowException;
use Psl\DateTime\Exception\UnderflowException;
use Psl\DateTime\Exception\UnexpectedValueException;
use Psl\DateTime\FormatPattern;
use Psl\DateTime\Internal;
use Psl\DateTime\Meridiem;
use Psl\DateTime\Month;
use Psl\DateTime\Period;
use Psl\DateTime\Timestamp;
use Psl\DateTime\TimeStyle;
use Psl\DateTime\Timezone;
use Psl\DateTime\Weekday;
use Psl\Json;
use Psl\Locale\Locale;

use function locale_set_default;
use function time;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class DateTimeTest extends TestCase
{
    use DateTimeTestTrait;

    public function testNow(): void
    {
        $timestamp = DateTime::now()->getTimestamp();

        static::assertEqualsWithDelta(time(), $timestamp->getSeconds(), 3);
    }

    public function testTodayAt(): void
    {
        $now = DateTime::now();
        $today = DateTime::todayAt(14, 0o0, 0o0);

        static::assertSame($now->getDate(), $today->getDate());
        static::assertNotSame($now->getTime(), $today->getTime());
        static::assertSame(14, $today->getHours());
        static::assertSame(0, $today->getMinutes());
        static::assertSame(0, $today->getSeconds());
        static::assertSame(0, $today->getNanoseconds());
    }

    public function testTodayAtDefaults(): void
    {
        $now = DateTime::now();
        $today = DateTime::todayAt(14, 0);

        static::assertSame($now->getDate(), $today->getDate());
        static::assertNotSame($now->getTime(), $today->getTime());
        static::assertSame(14, $today->getHours());
        static::assertSame(0, $today->getMinutes());
        static::assertSame(0, $today->getSeconds());
        static::assertSame(0, $today->getNanoseconds());
        static::assertSame(Timezone::default(), $today->getTimezone());
    }

    public function testFromParts(): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4, 14, 0, 0, 1);

        static::assertSame(Timezone::UTC, $datetime->getTimezone());
        static::assertSame(2024, $datetime->getYear());
        static::assertSame(24, $datetime->getYearShort());
        static::assertSame(2, $datetime->getMonth());
        static::assertSame(4, $datetime->getDay());
        static::assertSame(Weekday::Sunday, $datetime->getWeekday());
        static::assertSame(14, $datetime->getHours());
        static::assertSame(0, $datetime->getMinutes());
        static::assertSame(0, $datetime->getSeconds());
        static::assertSame(1, $datetime->getNanoseconds());
        static::assertSame([2024, 2, 4, 14, 0, 0, 1], $datetime->getParts());
    }

    public function testFromPartsWithDefaults(): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4);

        static::assertSame(Timezone::UTC, $datetime->getTimezone());
        static::assertSame(2024, $datetime->getYear());
        static::assertSame(2, $datetime->getMonth());
        static::assertSame(4, $datetime->getDay());
        static::assertSame(Weekday::Sunday, $datetime->getWeekday());
        static::assertSame(0, $datetime->getHours());
        static::assertSame(0, $datetime->getMinutes());
        static::assertSame(0, $datetime->getSeconds());
        static::assertSame(0, $datetime->getNanoseconds());
    }

    #[DataProvider('provideInvalidComponentParts')]
    public function testFromPartsWithInvalidComponent(
        string $expectedMessage,
        int $year,
        int $month,
        int $day,
        int $hours,
        int $minutes,
        int $seconds,
        int $nanoseconds,
    ): void {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        DateTime::fromParts(Timezone::UTC, $year, $month, $day, $hours, $minutes, $seconds, $nanoseconds);
    }

    public static function provideInvalidComponentParts(): array
    {
        return [
            [
                'Unexpected month value encountered. Provided "0", but the calendar expects "12". Ensure the month is within the 1-12 range and matches the specific year context.',
                2024,
                0,
                1,
                0,
                0,
                0,
                0,
            ],
            [
                'Unexpected day value encountered. Provided "0", but the calendar expects "31". Ensure the day is valid for the given month and year, considering variations like leap years.',
                2024,
                1,
                0,
                0,
                0,
                0,
                0,
            ],
            [
                'Unexpected hours value encountered. Provided "-1", but the calendar expects "23". Ensure the hour falls within a 24-hour day.',
                2024,
                1,
                1,
                -1,
                0,
                0,
                0,
            ],
            [
                'Unexpected minutes value encountered. Provided "-1", but the calendar expects "59". Check the minutes value for errors and ensure it\'s within the 0-59 range.',
                2024,
                1,
                1,
                0,
                -1,
                0,
                0,
            ],
            [
                'Unexpected seconds value encountered. Provided "-1", but the calendar expects "59". Ensure the seconds are correct and within the 0-59 range.',
                2024,
                1,
                1,
                0,
                0,
                -1,
                0,
            ],
        ];
    }

    public function testYearsOutsideTheSupportedRangeThrow(): void
    {
        static::assertSame(
            '5368709-12-31T23:59:59+00:00',
            DateTime::fromParts(Timezone::UTC, 5_368_709, 12, 31, 23, 59, 59)->toRfc3339(),
        );
        static::assertSame(
            '-5838268-01-01T00:00:00+00:00',
            DateTime::fromParts(Timezone::UTC, -5_838_268, 1, 1)->toRfc3339(),
        );

        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 1);

        foreach ([
            [static fn(): DateTime => DateTime::fromParts(Timezone::UTC, 5_368_710, 1, 1), OverflowException::class],
            [static fn(): DateTime => DateTime::fromParts(Timezone::UTC, PHP_INT_MAX, 1, 1), OverflowException::class],
            [static fn(): DateTime => DateTime::fromParts(Timezone::UTC, -5_838_269, 1, 1), UnderflowException::class],
            [static fn(): DateTime => DateTime::fromParts(Timezone::UTC, PHP_INT_MIN, 1, 1), UnderflowException::class],
            [static fn(): DateTime => $dt->withYear(6_000_000), OverflowException::class],
            [static fn(): DateTime => $dt->withDate(-6_000_000, 1, 1), UnderflowException::class],
        ] as [$operation, $exception]) {
            try {
                $operation();
                static::fail('Expected ' . $exception);
            } catch (OverflowException|UnderflowException $e) {
                static::assertInstanceOf($exception, $e);
            }
        }
    }

    public function testFromString(): void
    {
        $timezone = Timezone::EuropeBrussels;
        $datetime = DateTime::fromParts($timezone, 2024, Month::February, 4, 14, 0, 0, 0);

        $string = $datetime->toString();
        $parsed = DateTime::fromString($string, timezone: $timezone);

        static::assertEquals($datetime->getTimestamp(), $parsed->getTimestamp());
        static::assertSame($datetime->getTimezone(), $parsed->getTimezone());
        static::assertSame($string, $parsed->toString());
    }

    public function testToString(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertSame('4 Feb 2024, 14:00:00', $datetime->toString());
        static::assertSame('04/02/2024, 14:00:00', $datetime->toString(dateStyle: DateStyle::Short));
        static::assertSame('4 Feb 2024, 14:00:00 Greenwich Mean Time', $datetime->toString(timeStyle: TimeStyle::Full));
        static::assertSame('4 Feb 2024, 15:00:00', $datetime->toString(timezone: TimeZone::EuropeBrussels));

        // Formatting depends on version of intl - so compare with intl version instead of hardcoding a label:
        static::assertSame(
            Internal\create_intl_date_formatter(locale: Locale::DutchBelgium)
                ->format($datetime->getTimestamp()->getSeconds()),
            $datetime->toString(locale: Locale::DutchBelgium),
        );
    }

    public function testFormat(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertSame('4 Feb 2024, 14:00:00', $datetime->format());
        static::assertSame('02/04/2024', $datetime->format(pattern: FormatPattern::American));
        static::assertSame('02/04/2024', $datetime->format(pattern: FormatPattern::American->value));
        static::assertSame('4 Feb 2024, 15:00:00', $datetime->format(timezone: TimeZone::EuropeBrussels));

        // Formatting depends on version of intl - so compare with intl version instead of hardcoding a label:
        static::assertSame(
            Internal\create_intl_date_formatter(locale: Locale::DutchBelgium)
                ->format($datetime->getTimestamp()->getSeconds()),
            $datetime->toString(locale: Locale::DutchBelgium),
        );
    }

    public function testParse(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        $string = $datetime->format();
        $parsed = DateTime::parse($string);

        static::assertEquals($datetime->getTimestamp(), $parsed->getTimestamp());
        static::assertSame($datetime->getTimezone(), $parsed->getTimezone());
    }

    public function testParseWithTimezone(): void
    {
        $datetime = DateTime::fromParts(Timezone::AmericaNewYork, 2024, Month::February, 4, 14, 0, 0, 0);

        $string = $datetime->format();
        $parsed = DateTime::parse($string, timezone: TimeZone::AmericaNewYork);

        static::assertEquals($datetime->getTimestamp(), $parsed->getTimestamp());
        static::assertSame($datetime->getTimezone(), $parsed->getTimezone());
    }

    public function testWithDate(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);
        $new = $datetime->withDate(2025, Month::March, 5);

        static::assertSame(2025, $new->getYear());
        static::assertSame(3, $new->getMonth());
        static::assertSame(5, $new->getDay());
        static::assertSame(14, $new->getHours());
        static::assertSame(0, $new->getMinutes());
        static::assertSame(0, $new->getSeconds());
        static::assertSame(0, $new->getNanoseconds());
    }

    public function testWithMethods(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        $new = $datetime->withYear(2025);
        static::assertSame(2025, $new->getYear());

        $new = $datetime->withMonth(Month::March);
        static::assertSame(3, $new->getMonth());

        $new = $datetime->withDay(5);
        static::assertSame(5, $new->getDay());

        $new = $datetime->withHours(15);
        static::assertSame(15, $new->getHours());

        $new = $datetime->withMinutes(30);
        static::assertSame(30, $new->getMinutes());

        $new = $datetime->withSeconds(45);
        static::assertSame(45, $new->getSeconds());

        $new = $datetime->withNanoseconds(100);
        static::assertSame(100, $new->getNanoseconds());
    }

    public function testGetEra(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertSame('AD', $datetime->getEra()->value);
    }

    /**
     * @return iterable<array{int, string, string}>
     */
    public static function provideAstronomicalYears(): iterable
    {
        yield [0, 'BC 1', '0000-06-15T10:30:00+00:00'];
        yield [-1, 'BC 2', '-0001-06-15T10:30:00+00:00'];
        yield [-5, 'BC 6', '-0005-06-15T10:30:00+00:00'];
        yield [-4712, 'BC 4713', '-4712-06-15T10:30:00+00:00'];
        yield [1, 'AD 1', '0001-06-15T10:30:00+00:00'];
    }

    #[DataProvider('provideAstronomicalYears')]
    public function testYearsUseAstronomicalNumbering(int $year, string $eraYear, string $rfc3339): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, $year, 6, 15, 10, 30);

        static::assertSame($year, $datetime->getYear());
        static::assertSame($year > 0 ? Era::AnnoDomini : Era::BeforeChrist, $datetime->getEra());
        static::assertSame($year % 100, $datetime->getYearShort());
        static::assertSame($eraYear, $datetime->format('G y', locale: Locale::English));
        static::assertSame($rfc3339, $datetime->toRfc3339());

        $fromTimestamp = DateTime::fromTimestamp($datetime->getTimestamp(), Timezone::UTC);

        static::assertSame([$year, 6, 15, 10, 30, 0, 0], $fromTimestamp->getParts());
        static::assertSame($datetime->getEra(), $fromTimestamp->getEra());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideLocalesWithNonGregorianDefaultCalendars(): iterable
    {
        yield ['th_TH']; // Buddhist
        yield ['fa_IR']; // Persian
        yield ['ar_SA@calendar=islamic-umalqura'];
        yield ['ja_JP@calendar=japanese'];
    }

    #[DataProvider('provideLocalesWithNonGregorianDefaultCalendars')]
    public function testCalendarIsGregorianRegardlessOfDefaultLocale(string $locale): void
    {
        locale_set_default($locale);

        $datetime = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12);

        static::assertSame(1_705_320_000, $datetime->getTimestamp()->getSeconds());

        $fromTimestamp = DateTime::fromTimestamp(Timestamp::fromParts(1_705_320_000), Timezone::UTC);

        static::assertSame([2024, 1, 15, 12, 0, 0, 0], $fromTimestamp->getParts());
        static::assertSame([2024, 2, 15, 12, 0, 0, 0], $fromTimestamp->plusMonths(1)->getParts());
        static::assertSame(2024, $fromTimestamp->toIntl()->get(IntlCalendar::FIELD_EXTENDED_YEAR));
    }

    public function testToIntlKeepsTheWeekDataOfTheDefaultLocale(): void
    {
        locale_set_default('de_DE');

        $calendar = DateTime::fromParts(Timezone::UTC, 2024, 1, 7, 12)->toIntl();

        static::assertSame(IntlCalendar::DOW_MONDAY, $calendar->getFirstDayOfWeek());
        static::assertSame(4, $calendar->getMinimalDaysInFirstWeek());
        static::assertSame(1, $calendar->get(IntlCalendar::FIELD_WEEK_OF_YEAR));

        locale_set_default('fa_IR');

        $calendar = DateTime::fromParts(Timezone::UTC, 2024, 1, 7, 12)->toIntl();

        static::assertSame('gregorian', $calendar->getType());
        static::assertSame(IntlCalendar::DOW_SATURDAY, $calendar->getFirstDayOfWeek());
        static::assertSame(2024, $calendar->get(IntlCalendar::FIELD_EXTENDED_YEAR));
    }

    public function testJulianLeapDaysBeforeTheGregorianReform(): void
    {
        // Before 1582-10-15 ICU uses the Julian calendar, in which every fourth year is a leap year.
        foreach ([1500, 1300, -100] as $year) {
            $datetime = DateTime::fromParts(Timezone::UTC, $year, 2, 29, 12);

            static::assertSame([$year, 2, 29, 12, 0, 0, 0], $datetime->getParts());
            static::assertSame(
                [$year, 2, 29, 12, 0, 0, 0],
                DateTime::fromTimestamp($datetime->getTimestamp(), Timezone::UTC)->getParts(),
            );
            static::assertSame([$year, 3, 1, 12, 0, 0, 0], $datetime->plusDays(1)->getParts());
        }
    }

    public function testYearArithmeticCrossesIntoBeforeChrist(): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, 2024, 3, 1, 12);

        $result = $datetime->minusYears(2025);
        static::assertSame([-1, 3, 1, 12, 0, 0, 0], $result->getParts());
        static::assertSame(Era::BeforeChrist, $result->getEra());

        static::assertSame([-5, 3, 1, 12, 0, 0, 0], $datetime->withYear(-5)->getParts());
        static::assertSame([1, 3, 1, 12, 0, 0, 0], $result->plusYears(2)->getParts());
    }

    public function testGetCentury(): void
    {
        static::assertSame(1, DateTime::fromParts(Timezone::default(), 1, Month::January, 1)->getCentury());
        static::assertSame(1, DateTime::fromParts(Timezone::default(), 100, Month::January, 1)->getCentury());
        static::assertSame(2, DateTime::fromParts(Timezone::default(), 101, Month::January, 1)->getCentury());
        static::assertSame(20, DateTime::fromParts(Timezone::default(), 1999, Month::February, 4, 14)->getCentury());
        static::assertSame(20, DateTime::fromParts(Timezone::default(), 2000, Month::February, 4, 14)->getCentury());
        static::assertSame(21, DateTime::fromParts(Timezone::default(), 2001, Month::January, 1)->getCentury());
        static::assertSame(21, DateTime::fromParts(Timezone::default(), 2100, Month::January, 1)->getCentury());
    }

    public static function provideTwelveHours(): iterable
    {
        yield [0, 12, Meridiem::AnteMeridiem];
        yield [1, 1, Meridiem::AnteMeridiem];
        yield [2, 2, Meridiem::AnteMeridiem];
        yield [11, 11, Meridiem::AnteMeridiem];
        yield [12, 12, Meridiem::PostMeridiem];
        yield [13, 1, Meridiem::PostMeridiem];
        yield [14, 2, Meridiem::PostMeridiem];
        yield [23, 11, Meridiem::PostMeridiem];
    }

    #[DataProvider('provideTwelveHours')]
    public function testGetTwelveHours(int $hour, int $expectedTwelveHour, Meridiem $expectedMeridiem): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, $hour, 0, 0, 0);
        [$hours, $meridiem] = $datetime->getTwelveHours();

        static::assertSame($expectedTwelveHour, $hours);
        static::assertSame($expectedMeridiem, $meridiem);
    }

    public function testGetIsoWeek(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        [$year, $week] = $datetime->getISOWeekNumber();

        static::assertSame(2024, $year);
        static::assertSame(5, $week);

        $datetime = DateTime::fromParts(Timezone::default(), 2023, Month::January, 1, 14, 0, 0, 0);

        [$year, $week] = $datetime->getISOWeekNumber();

        static::assertSame(2022, $year);
        static::assertSame(52, $week);

        $datetime = DateTime::fromParts(Timezone::default(), 2025, Month::December, 31, 14, 0, 0, 0);

        [$year, $week] = $datetime->getISOWeekNumber();

        static::assertSame(2026, $year);
        static::assertSame(1, $week);
    }

    public function testPlusMethods(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        $new = $datetime->plusYears(1);
        static::assertSame(2025, $new->getYear());

        $new = $datetime->plusMonths(1);
        static::assertSame(3, $new->getMonth());

        $new = $datetime->plusMonths(0);
        static::assertSame($datetime, $new);

        $new = $datetime->plusMonths(-1);
        static::assertSame(1, $new->getMonth());

        $new = $datetime->plusDays(1);
        static::assertSame(5, $new->getDay());

        $new = $datetime->plusHours(1);
        static::assertSame(15, $new->getHours());

        $new = $datetime->plusMinutes(1);
        static::assertSame(1, $new->getMinutes());

        $new = $datetime->plusSeconds(1);
        static::assertSame(1, $new->getSeconds());

        $new = $datetime->plusNanoseconds(1);
        static::assertSame(1, $new->getNanoseconds());
    }

    public function testPlusMonthsEdgeCases(): void
    {
        $jan31 = DateTime::fromParts(Timezone::default(), 2024, Month::January, 31, 14, 0, 0, 0);
        $feb29 = $jan31->plusMonths(1);
        static::assertSame([2024, 2, 29], $feb29->getDate());
        static::assertSame([14, 0, 0, 0], $feb29->getTime());

        $dec31 = DateTime::fromParts(Timezone::default(), 2023, Month::December, 31, 14, 0, 0, 0);
        $mar31 = $dec31->plusMonths(3);
        static::assertSame([2024, 3, 31], $mar31->getDate());
        static::assertSame([14, 0, 0, 0], $mar31->getTime());

        $apr30 = $mar31->plusMonths(1);
        static::assertSame([2024, 4, 30], $apr30->getDate());
        static::assertSame([14, 0, 0, 0], $apr30->getTime());

        $apr30NextYear = $apr30->plusYears(1);
        static::assertSame([2025, 4, 30], $apr30NextYear->getDate());
        static::assertSame([14, 0, 0, 0], $apr30NextYear->getTime());
    }

    public function testPlusMonthOverflows(): void
    {
        $jan31Y2024 = DateTime::fromParts(Timezone::default(), 2024, Month::January, 31, 14, 0, 0, 0);
        $previousMonth = 1;
        for ($i = 1; $i < 24; $i++) {
            $res = $jan31Y2024->plusMonths($i);

            $expectedMonth = ($previousMonth + 1) % 12;
            $expectedMonth = 0 === $expectedMonth ? 12 : $expectedMonth;

            static::assertSame($res->getDay(), $res->getMonthEnum()->getDaysForYear($res->getYear()));
            static::assertSame($res->getMonth(), $expectedMonth);

            $previousMonth = $expectedMonth;
        }
    }

    public function testMinusMethods(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        $new = $datetime->minusYears(1);
        static::assertSame(2023, $new->getYear());

        $new = $datetime->minusMonths(1);
        static::assertSame(1, $new->getMonth());

        $new = $datetime->minusMonths(0);
        static::assertSame($datetime, $new);

        $new = $datetime->minusMonths(-1);
        static::assertSame(3, $new->getMonth());

        $new = $datetime->minusDays(1);
        static::assertSame(3, $new->getDay());

        $new = $datetime->minusHours(1);
        static::assertSame(13, $new->getHours());

        $new = $datetime->minusMinutes(1);
        static::assertSame(59, $new->getMinutes());

        $new = $datetime->minusSeconds(1);
        static::assertSame(59, $new->getSeconds());

        $new = $datetime->minusNanoseconds(1);
        static::assertSame(999_999_999, $new->getNanoseconds());
    }

    public function testMinusMonthsEdgeCases(): void
    {
        $feb29 = DateTime::fromParts(Timezone::default(), 2024, Month::February, 29, 14, 0, 0, 0);
        $jan29 = $feb29->minusMonths(1);
        static::assertSame([2024, 1, 29], $jan29->getDate());
        static::assertSame([14, 0, 0, 0], $jan29->getTime());

        $feb28PrevYear = $feb29->minusYears(1);
        static::assertSame([2023, 2, 28], $feb28PrevYear->getDate());
        static::assertSame([14, 0, 0, 0], $feb28PrevYear->getTime());

        $feb29PrevLeapYear = $feb29->minusYears(4);
        static::assertSame([2020, 2, 29], $feb29PrevLeapYear->getDate());
        static::assertSame([14, 0, 0, 0], $feb29PrevLeapYear->getTime());

        $mar31 = DateTime::fromParts(Timezone::default(), 2024, Month::March, 31, 14, 0, 0, 0);
        $dec31 = $mar31->minusMonths(3);
        static::assertSame([2023, 12, 31], $dec31->getDate());
        static::assertSame([14, 0, 0, 0], $dec31->getTime());

        $jan31 = $mar31->minusMonths(2);
        static::assertSame([2024, 1, 31], $jan31->getDate());
        static::assertSame([14, 0, 0, 0], $jan31->getTime());

        $may31 = DateTime::fromParts(Timezone::default(), 2024, Month::May, 31, 14, 0, 0, 0);
        $apr30 = $may31->minusMonths(1);
        static::assertSame([2024, 4, 30], $apr30->getDate());
        static::assertSame([14, 0, 0, 0], $apr30->getTime());

        $apr30PrevYear = $apr30->minusYears(1);
        static::assertSame([2023, 4, 30], $apr30PrevYear->getDate());
        static::assertSame([14, 0, 0, 0], $apr30PrevYear->getTime());
    }

    public function testMinusMonthOverflows(): void
    {
        $jan31Y2024 = DateTime::fromParts(Timezone::default(), 2024, Month::January, 31, 14, 0, 0, 0);
        $previousMonth = 1;
        for ($i = 1; $i < 24; $i++) {
            $res = $jan31Y2024->minusMonths($i);

            $expectedMonth = $previousMonth - 1;
            $expectedMonth = 0 === $expectedMonth ? 12 : $expectedMonth;

            static::assertSame($res->getDay(), $res->getMonthEnum()->getDaysForYear($res->getYear()));
            static::assertSame($res->getMonth(), $expectedMonth);

            $previousMonth = $expectedMonth;
        }
    }

    public function testIsLeapYear(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertTrue($datetime->isLeapYear());

        $datetime = DateTime::fromParts(Timezone::default(), 2023, Month::February, 4, 14, 0, 0, 0);

        static::assertFalse($datetime->isLeapYear());
    }

    public function testToRfc3999(): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertSame('2024-02-04T14:00:00+00:00', $datetime->toRfc3339());
    }

    public function testToRfc3339UseZWithUtcTimezone(): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertSame('2024-02-04T14:00:00Z', $datetime->toRfc3339(useZ: true));
    }

    public function testToRfc3339UseZWithNonUtcTimezone(): void
    {
        $datetime = DateTime::fromParts(Timezone::EuropeParis, 2024, Month::February, 4, 14, 0, 0, 0);

        $result = $datetime->toRfc3339(useZ: true);
        static::assertStringContainsString('+01:00', $result);
        static::assertStringNotContainsString('Z', $result);
    }

    public function testToRfc3339DoesNotDependOnTheDefaultLocale(): void
    {
        $timestamp = Timestamp::fromParts(1_705_320_000, 5_000_000);

        foreach (['ar_EG', 'fa_IR', 'hi_IN@numbers=deva'] as $defaultLocale) {
            locale_set_default($defaultLocale);

            static::assertSame('2024-01-15T12:00:00.005+00:00', $timestamp->toRfc3339());
            static::assertSame(
                '2024-01-15T13:00:00.005+01:00',
                DateTime::fromTimestamp($timestamp, Timezone::EuropeParis)->toRfc3339(),
            );
        }
    }

    public function testEqualIncludingTimezone(): void
    {
        $datetime1 = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4, 14, 0, 0, 0);
        $datetime2 = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertTrue($datetime1->equals($datetime2));
        static::assertFalse($datetime1->equalsIncludingTimezone($datetime2));

        $datetime1 = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4, 14, 0, 0, 0);
        $datetime2 = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertTrue($datetime1->equals($datetime2));
        static::assertTrue($datetime1->equalsIncludingTimezone($datetime2));

        $datetime1 = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);
        $datetime2 = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertTrue($datetime1->equals($datetime2));
        static::assertTrue($datetime1->equalsIncludingTimezone($datetime2));

        $datetime1 = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);
        $datetime2 = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 15, 0, 0, 0);

        static::assertFalse($datetime1->equals($datetime2));
        static::assertFalse($datetime1->equalsIncludingTimezone($datetime2));
    }

    public function testJsonSerialize(): void
    {
        $datetime = DateTime::fromParts(Timezone::default(), 2024, Month::February, 4, 14, 0, 0, 0);

        static::assertSame(
            '{"timezone":"Europe/London","timestamp":{"seconds":1707055200,"nanoseconds":0},"year":2024,"month":2,"day":4,"hours":14,"minutes":0,"seconds":0,"nanoseconds":0}',
            Json\encode($datetime),
        );
    }

    public function testWithTime(): void
    {
        $date = DateTime::todayAt(14, 0);
        $new = $date->withTime(15, 0);

        static::assertSame(15, $new->getHours());
        static::assertSame(0, $new->getMinutes());
        static::assertSame(0, $new->getSeconds());
        static::assertSame(0, $new->getNanoseconds());
    }

    public function testTimezoneInfo(): void
    {
        $timeZone = Timezone::EuropeBrussels;
        $date = DateTime::fromParts($timeZone, 2024, 0o1, 0o1);

        static::assertSame(!$timeZone->getDaylightSavingTimeOffset($date)->isZero(), $date->isDaylightSavingTime());
        static::assertEquals($timeZone->getOffset($date), $date->getTimezoneOffset());
    }

    public function testConvertTimeZone(): void
    {
        $date = DateTime::fromParts(Timezone::EuropeBrussels, 2024, 0o1, 0o1, 1);
        $converted = $date->convertToTimezone($london = Timezone::EuropeLondon);

        static::assertSame($london, $converted->getTimezone());
        static::assertSame($date->getTimestamp(), $converted->getTimestamp());
        static::assertSame($date->getYear(), $converted->getYear());
        static::assertSame($date->getMonth(), $converted->getMonth());
        static::assertSame($date->getDay(), $converted->getDay());
        static::assertSame(0, $converted->getHours());
    }

    public function testToStdlib(): void
    {
        $dt = DateTime::fromParts(Timezone::AmericaNewYork, 2024, 6, 15, 14, 30, 45, 123_456_000);

        $stdlib = $dt->toStdlib();

        static::assertInstanceOf(DateTimeImmutable::class, $stdlib);
        static::assertSame('America/New_York', $stdlib->getTimezone()->getName());
        static::assertSame('2024', $stdlib->format('Y'));
        static::assertSame('06', $stdlib->format('m'));
        static::assertSame('15', $stdlib->format('d'));
        static::assertSame('14', $stdlib->format('H'));
        static::assertSame('30', $stdlib->format('i'));
        static::assertSame('45', $stdlib->format('s'));
        static::assertSame('123456', $stdlib->format('u'));
    }

    public function testFromStdlib(): void
    {
        $stdlib = new DateTimeImmutable('2024-06-15 14:30:45.123456', new DateTimeZone('America/New_York'));

        $dt = DateTime::fromStdlib($stdlib);

        static::assertSame(Timezone::AmericaNewYork, $dt->getTimezone());
        static::assertSame(2024, $dt->getYear());
        static::assertSame(6, $dt->getMonth());
        static::assertSame(15, $dt->getDay());
        static::assertSame(14, $dt->getHours());
        static::assertSame(30, $dt->getMinutes());
        static::assertSame(45, $dt->getSeconds());
        static::assertSame(123_456_000, $dt->getNanoseconds());
    }

    public function testStdlibRoundTrip(): void
    {
        $original = DateTime::fromParts(Timezone::EuropeParis, 2024, 12, 25, 10, 0, 0, 500_000_000);

        $roundTripped = DateTime::fromStdlib($original->toStdlib());

        static::assertSame($original->getYear(), $roundTripped->getYear());
        static::assertSame($original->getMonth(), $roundTripped->getMonth());
        static::assertSame($original->getDay(), $roundTripped->getDay());
        static::assertSame($original->getHours(), $roundTripped->getHours());
        static::assertSame($original->getMinutes(), $roundTripped->getMinutes());
        static::assertSame($original->getSeconds(), $roundTripped->getSeconds());
        // microsecond precision preserved (nanoseconds truncated to microseconds)
        static::assertSame(500_000_000, $roundTripped->getNanoseconds());
        static::assertSame($original->getTimezone(), $roundTripped->getTimezone());
    }

    public function testToIntl(): void
    {
        $dt = DateTime::fromParts(Timezone::AsiaShanghai, 2024, 3, 15, 12, 0, 0);

        $calendar = $dt->toIntl();

        static::assertInstanceOf(IntlCalendar::class, $calendar);
        static::assertSame(2024, $calendar->get(IntlCalendar::FIELD_YEAR));
        static::assertSame(2, $calendar->get(IntlCalendar::FIELD_MONTH)); // 0-indexed
        static::assertSame(15, $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH));
        static::assertSame(12, $calendar->get(IntlCalendar::FIELD_HOUR_OF_DAY));
        static::assertSame(0, $calendar->get(IntlCalendar::FIELD_MINUTE));
        static::assertSame(0, $calendar->get(IntlCalendar::FIELD_SECOND));
    }

    public function testFromIntl(): void
    {
        $calendar = IntlCalendar::createInstance('America/New_York');
        $calendar->setDateTime(2024, 5, 15, 14, 30, 45); // month is 0-indexed

        $dt = DateTime::fromIntl($calendar);

        static::assertSame(Timezone::AmericaNewYork, $dt->getTimezone());
        static::assertSame(2024, $dt->getYear());
        static::assertSame(6, $dt->getMonth());
        static::assertSame(15, $dt->getDay());
        static::assertSame(14, $dt->getHours());
        static::assertSame(30, $dt->getMinutes());
        static::assertSame(45, $dt->getSeconds());
    }

    public function testIntlRoundTrip(): void
    {
        $original = DateTime::fromParts(Timezone::UTC, 2024, 1, 1, 0, 0, 0);

        $roundTripped = DateTime::fromIntl($original->toIntl());

        static::assertSame($original->getYear(), $roundTripped->getYear());
        static::assertSame($original->getMonth(), $roundTripped->getMonth());
        static::assertSame($original->getDay(), $roundTripped->getDay());
        static::assertSame($original->getHours(), $roundTripped->getHours());
        static::assertSame($original->getMinutes(), $roundTripped->getMinutes());
        static::assertSame($original->getSeconds(), $roundTripped->getSeconds());
        static::assertSame($original->getTimezone(), $roundTripped->getTimezone());
    }

    /**
     * 2024-10-27 01:30 happens twice in Europe/London: at 00:30 UTC (BST) and at 01:30 UTC (GMT).
     *
     * @return iterable<array{int}>
     */
    public static function provideAmbiguousLondonInstants(): iterable
    {
        yield [1_729_989_000];
        yield [1_729_992_600];
    }

    #[DataProvider('provideAmbiguousLondonInstants')]
    public function testToStdlibAndToIntlKeepTheInstantOfAnAmbiguousLocalTime(int $seconds): void
    {
        $dt = DateTime::fromTimestamp(Timestamp::fromParts($seconds, 250_000_000), Timezone::EuropeLondon);

        static::assertSame([2024, 10, 27, 1, 30, 0, 250_000_000], $dt->getParts());

        $stdlib = $dt->toStdlib();
        static::assertSame($seconds, $stdlib->getTimestamp());
        static::assertSame('250000', $stdlib->format('u'));
        static::assertSame('Europe/London', $stdlib->getTimezone()->getName());

        $calendar = $dt->toIntl();
        static::assertSame(($seconds * 1000) + 250, (int) $calendar->getTime());
        static::assertSame(250, $calendar->get(IntlCalendar::FIELD_MILLISECOND));

        static::assertTrue($dt->equalsIncludingTimezone(DateTime::fromStdlib($stdlib)));
        static::assertTrue($dt->equalsIncludingTimezone(DateTime::fromIntl($calendar)));
    }

    public function testToStdlibKeepsTheInstantOfBeforeChristAndJulianDates(): void
    {
        foreach ([[-5, 1, 1], [0, 2, 29], [1000, 1, 1], [1582, 10, 4]] as [$year, $month, $day]) {
            $dt = DateTime::fromParts(Timezone::UTC, $year, $month, $day, 12, 0, 0, 1_000);
            $stdlib = $dt->toStdlib();

            static::assertSame($dt->getTimestamp()->getSeconds(), $stdlib->getTimestamp());
            static::assertSame('000001', $stdlib->format('u'));
            static::assertSame([$year, $month, $day, 12, 0, 0, 1_000], DateTime::fromStdlib($stdlib)->getParts());
        }
    }

    public function testNanosecondsBoundaryMaxValid(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 30, 45, 999_999_999);

        static::assertSame(999_999_999, $dt->getNanoseconds());
    }

    public function testNanosecondsOverLimitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 30, 45, 1_000_000_000);
    }

    public function testNegativeNanosecondsThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 30, 45, -1);
    }

    public function testSecondsBoundaryMaxValid(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 30, 59, 0);

        static::assertSame(59, $dt->getSeconds());
    }

    public function testSecondsOverLimitThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 30, 60, 0);
    }

    public function testMinutesBoundaryMaxValid(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 59, 0, 0);

        static::assertSame(59, $dt->getMinutes());
    }

    public function testMinutesOverLimitThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 60, 0, 0);
    }

    public function testHoursBoundaryMaxValid(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 23, 0, 0, 0);

        static::assertSame(23, $dt->getHours());
    }

    public function testHoursOverLimitThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 24, 0, 0, 0);
    }

    public function testDayBoundaryMaxValidForJanuary(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, Month::January, 31, 0, 0, 0, 0);

        static::assertSame(31, $dt->getDay());
    }

    public function testDayOverLimitForJanuaryThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DateTime::fromParts(Timezone::UTC, 2024, Month::January, 32, 0, 0, 0, 0);
    }

    public function testDayBoundaryForFebruaryLeapYear(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, Month::February, 29, 0, 0, 0, 0);

        static::assertSame(29, $dt->getDay());
    }

    public function testDayOverLimitForFebruaryLeapYearThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DateTime::fromParts(Timezone::UTC, 2024, Month::February, 30, 0, 0, 0, 0);
    }

    public function testMicrosecondPrecisionStdlibRoundTrip(): void
    {
        // Test various microsecond values to ensure precision is maintained
        $microsecondValues = [0, 1_000, 123_456_000, 500_000_000, 999_999_000];

        foreach ($microsecondValues as $nanoseconds) {
            $original = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 14, 30, 45, $nanoseconds);
            $stdlib = $original->toStdlib();
            $roundTripped = DateTime::fromStdlib($stdlib);

            // microsecond precision preserved (nanoseconds truncated to microsecond granularity)
            static::assertSame(
                $nanoseconds,
                $roundTripped->getNanoseconds(),
                "Nanoseconds precision lost for value {$nanoseconds}",
            );
        }
    }

    public function testFromStdlibMicrosecondCast(): void
    {
        $stdlib = new DateTimeImmutable('2024-06-15 14:30:45.000001', new DateTimeZone('UTC'));

        $dt = DateTime::fromStdlib($stdlib);

        // 1 microsecond = 1000 nanoseconds
        static::assertSame(1_000, $dt->getNanoseconds());
    }

    public function testToStdlibNanosecondDivisionCast(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 14, 30, 45, 1_500);

        $stdlib = $dt->toStdlib();

        // 1500 nanoseconds / 1000 = 1.5 microseconds, truncated to 1 microsecond
        static::assertSame('000001', $stdlib->format('u'));
    }

    public function testFormatWithNanosecondPrecision(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 12, 0, 0, 500_000_000);

        // Test that formatting keeps non-zero nanoseconds, to the millisecond.
        $formatted = $dt->format(pattern: FormatPattern::Iso8601);

        static::assertStringContainsString('.5', $formatted);
    }

    public function testPlusMonthsZeroReturnsSameInstance(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 12, 0, 0, 0);

        $result = $dt->plusMonths(0);

        static::assertSame($dt, $result);
    }

    public function testPlusMonthsNegativeDelegatesToMinusMonths(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 12, 0, 0, 0);

        $result = $dt->plusMonths(-5);

        static::assertSame(2024, $result->getYear());
        static::assertSame(1, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testMinusMonthsZeroReturnsSameInstance(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 12, 0, 0, 0);

        $result = $dt->minusMonths(0);

        static::assertSame($dt, $result);
    }

    public function testMinusMonthsNegativeDelegatesToPlusMonths(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 12, 0, 0, 0);

        $result = $dt->minusMonths(-5);

        static::assertSame(2024, $result->getYear());
        static::assertSame(11, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testPlusMonthsNegativeCrossingYearBoundary(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 3, 15, 12, 0, 0, 0);

        $result = $dt->plusMonths(-7);

        static::assertSame(2023, $result->getYear());
        static::assertSame(8, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testMinusMonthsNegativeCrossingYearBoundary(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 12, 0, 0, 0);

        $result = $dt->minusMonths(-7);

        static::assertSame(2025, $result->getYear());
        static::assertSame(1, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testFromIntlWithMillisecondPrecision(): void
    {
        $calendar = IntlCalendar::createInstance('UTC');
        $calendar->setTime(1_718_453_445_500.0);

        $dt = DateTime::fromIntl($calendar);

        static::assertSame(500_000_000, $dt->getNanoseconds());
    }

    public function testFromIntlWithNonZeroMilliseconds(): void
    {
        $calendar = IntlCalendar::createInstance('America/New_York');
        $calendar->setTime(1_718_453_445_123.0);

        $dt = DateTime::fromIntl($calendar);

        static::assertSame(123_000_000, $dt->getNanoseconds());
    }

    public function testFromIntlMillisecondRoundTrip(): void
    {
        $calendar = IntlCalendar::createInstance('UTC');
        $calendar->setTime(1_718_453_445_750.0);

        $dt = DateTime::fromIntl($calendar);

        static::assertSame(750_000_000, $dt->getNanoseconds());
    }

    public function testFromIntlTruncatesFractionalMillisecondsTowardNegativeInfinity(): void
    {
        $calendar = IntlCalendar::createInstance('UTC');

        $calendar->setTime(1_735_689_599_999.9);
        static::assertSame([1_735_689_599, 999_000_000], DateTime::fromIntl($calendar)->getTimestamp()->toParts());

        $calendar->setTime(-0.5);
        static::assertSame([-1, 999_000_000], DateTime::fromIntl($calendar)->getTimestamp()->toParts());
        static::assertSame('1969-12-31 23:59:59.999', DateTime::fromIntl($calendar)->format('yyyy-MM-dd HH:mm:ss.SSS'));

        $calendar->setTime(-1_500.25);
        static::assertSame([-2, 499_000_000], DateTime::fromIntl($calendar)->getTimestamp()->toParts());
    }

    public function testFromPartsFloatCastInTimestampCalculation(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 14, 30, 45, 0);

        static::assertSame(45, $dt->getSeconds());
        static::assertSame(0, $dt->getNanoseconds());

        $dt2 = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 14, 30, 45, 500_000_000);

        static::assertSame(45, $dt2->getSeconds());
        static::assertSame(500_000_000, $dt2->getNanoseconds());
    }

    public function testFromPartsBeforeTheEpochIsNotOffByOneSecond(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 1969, 12, 31, 23, 59, 59);

        static::assertSame(-1, $dt->getTimestamp()->getSeconds());
        static::assertSame('1969-12-31T23:59:59+00:00', $dt->toRfc3339());

        $dt = DateTime::fromParts(Timezone::EuropeLondon, 1900, 6, 15, 12, 30, 15);
        $roundTrip = DateTime::fromTimestamp($dt->getTimestamp(), Timezone::EuropeLondon);

        static::assertSame([1900, 6, 15, 12, 30, 15, 0], $roundTrip->getParts());
    }

    public function testPlusMonthsBeforeTheEpochIsNotOffByOneSecond(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 1960, 1, 15, 10, 0, 0);

        static::assertSame([1960, 2, 15, 10, 0, 0, 0], $dt->plus(Period::months(1))->getParts());
    }

    public function testPlusMonthsWrapsAroundYear(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 10, 15, 12, 0, 0, 0);

        $result = $dt->plusMonths(5);

        static::assertSame(2025, $result->getYear());
        static::assertSame(3, $result->getMonth());
    }

    public function testMinusMonthsWrapsAroundYear(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 3, 15, 12, 0, 0, 0);

        $result = $dt->minusMonths(5);

        static::assertSame(2023, $result->getYear());
        static::assertSame(10, $result->getMonth());
    }

    public function testPlusPeriod(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 31, 12, 0, 0);

        // Adding 1 month to Jan 31 should clamp to Feb 28
        $result = $dt->plus(Period::months(1));

        static::assertSame(2025, $result->getYear());
        static::assertSame(2, $result->getMonth());
        static::assertSame(28, $result->getDay());
        static::assertSame(12, $result->getHours());
    }

    public function testPlusPeriodWithDays(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 1, 0, 0, 0);

        $result = $dt->plus(Period::fromParts(1, 2, 15));

        static::assertSame(2026, $result->getYear());
        static::assertSame(3, $result->getMonth());
        static::assertSame(16, $result->getDay());
    }

    public function testMinusPeriod(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 3, 31, 12, 0, 0);

        // Subtracting 1 month from Mar 31 should clamp to Feb 28
        $result = $dt->minus(Period::months(1));

        static::assertSame(2025, $result->getYear());
        static::assertSame(2, $result->getMonth());
        static::assertSame(28, $result->getDay());
    }

    public function testPlusWeeks(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 1, 12, 0, 0);

        $result = $dt->plusWeeks(2);

        static::assertSame(2025, $result->getYear());
        static::assertSame(1, $result->getMonth());
        static::assertSame(15, $result->getDay());
        static::assertSame(12, $result->getHours());
    }

    public function testMinusWeeks(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 15, 12, 0, 0);

        $result = $dt->minusWeeks(2);

        static::assertSame(2025, $result->getYear());
        static::assertSame(1, $result->getMonth());
        static::assertSame(1, $result->getDay());
    }

    public function testDayArithmeticKeepsTheWallClockTimeAcrossDaylightSavingTime(): void
    {
        // Europe/London springs forward on 2024-03-31: that day has 23 hours.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 3, 30, 12, 0, 0, 42);

        static::assertSame('2024-03-31T12:00:00.000000042+01:00', $dt->plusDays(1)->toRfc3339());
        static::assertSame('2024-03-31T12:00:00.000000042+01:00', $dt->plus(Period::days(1))->toRfc3339());
        static::assertSame('2024-04-06T12:00:00.000000042+01:00', $dt->plusWeeks(1)->toRfc3339());
        static::assertSame('2024-04-06T12:00:00.000000042+01:00', $dt->plus(Period::weeks(1))->toRfc3339());

        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 4, 1, 12);

        static::assertSame('2024-03-30T12:00:00+00:00', $dt->minusDays(2)->toRfc3339());
        static::assertSame('2024-03-30T12:00:00+00:00', $dt->minus(Period::days(2))->toRfc3339());
        static::assertSame('2024-03-25T12:00:00+00:00', $dt->minusWeeks(1)->toRfc3339());
        static::assertSame('2024-03-25T12:00:00+00:00', $dt->minus(Period::weeks(1))->toRfc3339());

        // Europe/London falls back on 2024-10-27: that day has 25 hours.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 28, 12);

        static::assertSame('2024-10-26T12:00:00+01:00', $dt->minusDays(2)->toRfc3339());
        static::assertSame('2024-10-29T12:00:00+00:00', $dt->minusDays(2)->plusDays(3)->toRfc3339());
        static::assertSame('2024-10-21T12:00:00+01:00', $dt->minusWeeks(1)->toRfc3339());

        // The start and end of the week are computed with day arithmetic as well.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 30, 12);

        static::assertSame('2024-10-28T00:00:00+00:00', $dt->atStartOfWeek()->toRfc3339());

        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 22, 12);

        static::assertSame('2024-10-27T23:59:59.999999999+00:00', $dt->atEndOfWeek()->toRfc3339());
    }

    public function testDayArithmeticIntoASkippedOrRepeatedWallClockTime(): void
    {
        // 01:30 does not exist on 2024-03-31 in Europe/London; it moves forward by the one-hour gap.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 3, 30, 1, 30);

        static::assertSame('2024-03-31T02:30:00+01:00', $dt->plusDays(1)->toRfc3339());
        static::assertSame('2024-04-01T01:30:00+01:00', $dt->plusDays(2)->toRfc3339());

        // 01:30 happens twice on 2024-10-27; the occurrence with the current offset is kept.
        $bst = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 26, 1, 30);
        $gmt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 28, 1, 30);

        static::assertSame('2024-10-27T01:30:00+01:00', $bst->plusDays(1)->toRfc3339());
        static::assertSame('2024-10-27T01:30:00+00:00', $gmt->minusDays(1)->toRfc3339());

        // Pacific/Apia skipped 2011-12-30 entirely.
        $dt = DateTime::fromParts(Timezone::PacificApia, 2011, 12, 29, 12);

        static::assertSame('2011-12-31T12:00:00+14:00', $dt->plusDays(1)->toRfc3339());
        static::assertSame('2011-12-31T12:00:00+14:00', $dt->plusDays(2)->toRfc3339());
    }

    public function testPeriodAddsMonthsThenDaysOnTheWallClock(): void
    {
        // 2024-03-31 01:30 does not exist in Europe/London, but 2024-04-01 01:30 does.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2023, 3, 31, 1, 30);

        static::assertSame('2024-04-01T01:30:00+01:00', $dt->plus(Period::fromParts(1, 0, 1))->toRfc3339());
        static::assertSame(
            '2023-03-31T01:30:00+01:00',
            $dt
                ->plus(Period::fromParts(1, 0, 1))
                ->minus(Period::fromParts(1, 0, 1))
                ->toRfc3339(),
        );
    }

    public function testMonthAndYearArithmeticIntoASkippedWallClockTime(): void
    {
        // 01:30 does not exist on 2024-03-31 in Europe/London; it moves forward by the one-hour gap.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2023, 3, 31, 1, 30);

        static::assertSame('2024-03-31T02:30:00+01:00', $dt->plusYears(1)->toRfc3339());
        static::assertSame('2024-03-31T02:30:00+01:00', $dt->plusMonths(12)->toRfc3339());

        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 5, 31, 1, 30);

        static::assertSame('2024-03-31T02:30:00+01:00', $dt->minusMonths(2)->toRfc3339());

        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2025, 3, 31, 1, 30);

        static::assertSame('2024-03-31T02:30:00+01:00', $dt->minusYears(1)->toRfc3339());

        // America/Santiago skips midnight when daylight saving time starts (2024-09-08 00:00 → 01:00).
        $dt = DateTime::fromParts(Timezone::AmericaSantiago, 2024, 8, 8);

        static::assertSame('2024-09-08T01:00:00-03:00', $dt->plusMonths(1)->toRfc3339());
    }

    public function testMonthAndYearArithmeticOutsideTheSupportedRangeThrows(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 1);

        static::assertSame('5368709-01-01T00:00:00+00:00', $dt->plusYears(5_366_685)->toRfc3339());
        static::assertSame('-5838268-01-01T00:00:00+00:00', $dt->minusYears(5_840_292)->toRfc3339());

        foreach ([
            [static fn(): DateTime => $dt->plusYears(5_366_686), OverflowException::class],
            [static fn(): DateTime => $dt->minusYears(5_840_293), UnderflowException::class],
            [static fn(): DateTime => $dt->plusMonths(72_000_000), OverflowException::class],
            [static fn(): DateTime => $dt->minusMonths(72_000_000), UnderflowException::class],
            [static fn(): DateTime => $dt->plusYears(6_000_000), OverflowException::class],
            [static fn(): DateTime => $dt->minusYears(6_000_000), UnderflowException::class],
            [static fn(): DateTime => $dt->plusYears(PHP_INT_MAX), OverflowException::class],
            [static fn(): DateTime => $dt->minusYears(PHP_INT_MAX), UnderflowException::class],
            [static fn(): DateTime => $dt->plusMonths(PHP_INT_MIN), UnderflowException::class],
            [static fn(): DateTime => $dt->minusMonths(PHP_INT_MIN), OverflowException::class],
        ] as [$operation, $exception]) {
            try {
                $operation();
                static::fail('Expected ' . $exception);
            } catch (OverflowException|UnderflowException $e) {
                static::assertInstanceOf($exception, $e);
            }
        }
    }

    public function testStartAndEndHelpersOnDaysWithASkippedOrRepeatedBoundary(): void
    {
        // Midnight is skipped when daylight saving time starts in these zones; the day starts at 01:00.
        $dt = DateTime::fromParts(Timezone::AfricaCairo, 2024, 4, 26, 12);
        static::assertSame('2024-04-26T01:00:00+03:00', $dt->atStartOfDay()->toRfc3339());

        $dt = DateTime::fromParts(Timezone::AmericaSantiago, 2024, 9, 8, 12);
        static::assertSame('2024-09-08T01:00:00-03:00', $dt->atStartOfDay()->toRfc3339());

        $dt = DateTime::fromParts(Timezone::AmericaAsuncion, 2023, 10, 15, 12);
        static::assertSame('2023-10-01T01:00:00-03:00', $dt->atStartOfMonth()->toRfc3339());

        // America/Nuuk skips 23:00-00:00 on 2024-03-30; the day ends just before the transition.
        $dt = DateTime::fromParts(Timezone::AmericaNuuk, 2024, 3, 30, 12);
        static::assertSame('2024-03-30T22:59:59.999999999-02:00', $dt->atEndOfDay()->toRfc3339());

        // Cuba repeats 00:00-01:00 on 2024-11-03; the day starts at the first midnight.
        $dt = DateTime::fromParts(Timezone::AmericaHavana, 2024, 11, 3, 12);
        static::assertSame('2024-11-03T00:00:00-04:00', $dt->atStartOfDay()->toRfc3339());
    }

    public function testStartAndEndHelpersOnAGapThatStraddlesMidnight(): void
    {
        // America/Toronto skipped 23:30-00:30 on 1919-03-30/31, so neither boundary is on the edge of the gap
        // shifted by its length: Monday 1919-03-31 starts at 00:30, and Sunday 1919-03-30 ends at 23:29:59.
        $dt = DateTime::fromParts(Timezone::AmericaToronto, 1919, 3, 31, 0, 45);
        static::assertSame('1919-03-31T00:30:00-04:00', $dt->atStartOfDay()->toRfc3339());
        static::assertSame('1919-03-31T00:30:00-04:00', $dt->atStartOfWeek()->toRfc3339());

        $dt = DateTime::fromParts(Timezone::AmericaToronto, 1919, 3, 30, 23, 15);
        static::assertSame('1919-03-30T23:29:59.999999999-05:00', $dt->atEndOfDay()->toRfc3339());
        static::assertSame('1919-03-30T23:29:59.999999999-05:00', $dt->atEndOfWeek()->toRfc3339());

        // Pacific/Kiritimati skipped the whole of 1994-12-31, so 1994 ends on December 30th.
        $dt = DateTime::fromParts(Timezone::PacificKiritimati, 1994, 12, 30, 12);
        static::assertSame('1994-12-30T23:59:59.999999999-10:00', $dt->atEndOfYear()->toRfc3339());
    }

    public function testWeekHelpersMoveTheDateBeforeResolvingTheBoundary(): void
    {
        // Africa/Algiers skipped 23:00-00:00 on Sunday 1971-04-25. The week of Wednesday 23:30 still ends on
        // that Sunday, just before the transition, even though Sunday 23:30 does not exist.
        $dt = DateTime::fromParts(Timezone::AfricaAlgiers, 1971, 4, 21, 23, 30);
        static::assertSame('1971-04-25T22:59:59.999999999+00:00', $dt->atEndOfWeek()->toRfc3339());

        // Asia/Tehran skipped 23:00-00:00 on Monday 1977-03-21. The week of Wednesday 23:30 still starts on
        // that Monday, even though Monday 23:30 does not exist.
        $dt = DateTime::fromParts(Timezone::AsiaTehran, 1977, 3, 23, 23, 30);
        static::assertSame('1977-03-21T00:00:00+03:30', $dt->atStartOfWeek()->toRfc3339());
    }

    public function testStartAndEndHelpersOutsideTheSupportedRangeThrow(): void
    {
        // About the year 5389000: an instant ICU represents, but whose dates it cannot compute times for.
        $dt = DateTime::fromTimestamp(Timestamp::fromParts(170_000_000_000_000), Timezone::UTC);

        foreach ([
            $dt->atStartOfDay(...),
            $dt->atEndOfDay(...),
            $dt->atStartOfMonth(...),
            $dt->atEndOfYear(...),
            $dt->atStartOfWeek(...),
        ] as $operation) {
            try {
                $operation();
                static::fail('Expected an OverflowException.');
            } catch (OverflowException $e) {
                static::assertStringContainsString('5368709', $e->getMessage());
            }
        }
    }

    public function testDayArithmeticOverflow(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 1);

        foreach ([
            [static fn(): DateTime => $dt->plusDays(PHP_INT_MAX), OverflowException::class],
            [static fn(): DateTime => $dt->minusDays(PHP_INT_MIN), OverflowException::class],
            [static fn(): DateTime => $dt->plusWeeks(PHP_INT_MAX), OverflowException::class],
            [static fn(): DateTime => $dt->minusDays(PHP_INT_MAX), UnderflowException::class],
            [static fn(): DateTime => $dt->plusWeeks(PHP_INT_MIN), UnderflowException::class],
            [static fn(): DateTime => $dt->minus(Period::days(PHP_INT_MAX)), UnderflowException::class],
        ] as [$operation, $exception]) {
            try {
                $operation();
                static::fail('Expected ' . $exception);
            } catch (OverflowException|UnderflowException $e) {
                static::assertInstanceOf($exception, $e);
            }
        }
    }

    public function testOutOfRangeMonthArithmeticDoesNotReportAClampedYear(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 1);

        try {
            $dt->plus(Period::years(PHP_INT_MAX));
            static::fail('Expected an OverflowException.');
        } catch (OverflowException $e) {
            static::assertSame(
                'The resulting date is after 5368709-12-31, the latest supported date.',
                $e->getMessage(),
            );
        }

        try {
            $dt->minus(Period::years(PHP_INT_MAX));
            static::fail('Expected an UnderflowException.');
        } catch (UnderflowException $e) {
            static::assertSame(
                'The resulting date is before -5838268-01-01, the earliest supported date.',
                $e->getMessage(),
            );
        }
    }

    public function testDayArithmeticCoversTheSupportedRange(): void
    {
        // More days than the 32 bits `IntlCalendar::add()` takes, with a result within the supported range.
        $dt = DateTime::fromParts(Timezone::UTC, -5_000_000, 1, 1, 12);
        $later = $dt->plusDays(3_000_000_000);

        static::assertSame('3213618-05-06T12:00:00+00:00', $later->toRfc3339());
        static::assertSame($dt->toRfc3339(), $later->minusDays(3_000_000_000)->toRfc3339());

        // The edges of the supported range.
        static::assertSame(
            '5368709-12-31T12:00:00+00:00',
            DateTime::fromParts(Timezone::UTC, 5_368_709, 12, 30, 12)->plusDays(1)->toRfc3339(),
        );
        static::assertSame(
            '-5838268-01-01T12:00:00+00:00',
            DateTime::fromParts(Timezone::UTC, -5_838_268, 1, 2, 12)->minusDays(1)->toRfc3339(),
        );

        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 1);

        foreach ([
            [
                static fn(): DateTime => DateTime::fromParts(Timezone::UTC, 5_368_709, 12, 31)->plusDays(1),
                OverflowException::class,
            ],
            [
                static fn(): DateTime => DateTime::fromParts(Timezone::UTC, -5_838_268, 1, 1)->minusDays(1),
                UnderflowException::class,
            ],
            [static fn(): DateTime => $dt->plusDays(2_100_000_000), OverflowException::class],
            [static fn(): DateTime => $dt->plusWeeks(300_000_000), OverflowException::class],
            [static fn(): DateTime => $dt->minusDays(2_140_000_000), UnderflowException::class],
            [static fn(): DateTime => $dt->plus(Period::days(2_100_000_000)), OverflowException::class],
            [static fn(): DateTime => $dt->plus(Period::years(6_000_000)), OverflowException::class],
            [static fn(): DateTime => $dt->minus(Period::years(6_000_000)), UnderflowException::class],
        ] as [$operation, $exception]) {
            try {
                $operation();
                static::fail('Expected ' . $exception);
            } catch (OverflowException|UnderflowException $e) {
                static::assertInstanceOf($exception, $e);
            }
        }
    }

    public function testFromPartsResolvesARepeatedTimeToItsLaterOccurrence(): void
    {
        // 01:30 happens twice on 2024-10-27 in Europe/London.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 27, 1, 30);

        static::assertSame('2024-10-27T01:30:00+00:00', $dt->toRfc3339());
        static::assertSame('2024-10-27T01:30:00+01:00', $dt->minusHours(1)->toRfc3339());
    }

    public function testGetDayOfYear(): void
    {
        $jan1 = DateTime::fromParts(Timezone::UTC, 2025, 1, 1);
        static::assertSame(1, $jan1->getDayOfYear());

        $feb1 = DateTime::fromParts(Timezone::UTC, 2025, 2, 1);
        static::assertSame(32, $feb1->getDayOfYear());

        $dec31 = DateTime::fromParts(Timezone::UTC, 2025, 12, 31);
        static::assertSame(365, $dec31->getDayOfYear());

        // Leap year
        $dec31Leap = DateTime::fromParts(Timezone::UTC, 2024, 12, 31);
        static::assertSame(366, $dec31Leap->getDayOfYear());
    }

    public function testAtStartOfDay(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 6, 15, 14, 30, 45, 123_456_789);
        $start = $dt->atStartOfDay();

        static::assertSame(2025, $start->getYear());
        static::assertSame(6, $start->getMonth());
        static::assertSame(15, $start->getDay());
        static::assertSame(0, $start->getHours());
        static::assertSame(0, $start->getMinutes());
        static::assertSame(0, $start->getSeconds());
        static::assertSame(0, $start->getNanoseconds());
    }

    public function testAtEndOfDay(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 6, 15, 14, 30, 45, 0);
        $end = $dt->atEndOfDay();

        static::assertSame(2025, $end->getYear());
        static::assertSame(6, $end->getMonth());
        static::assertSame(15, $end->getDay());
        static::assertSame(23, $end->getHours());
        static::assertSame(59, $end->getMinutes());
        static::assertSame(59, $end->getSeconds());
        static::assertSame(999_999_999, $end->getNanoseconds());
    }

    public function testAtStartOfMonth(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 6, 15, 14, 30, 45, 123_456_789);
        $start = $dt->atStartOfMonth();

        static::assertSame(2025, $start->getYear());
        static::assertSame(6, $start->getMonth());
        static::assertSame(1, $start->getDay());
        static::assertSame(0, $start->getHours());
        static::assertSame(0, $start->getMinutes());
        static::assertSame(0, $start->getSeconds());
        static::assertSame(0, $start->getNanoseconds());
    }

    public function testAtEndOfMonth(): void
    {
        // June has 30 days
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 6, 15, 14, 30, 45, 0);
        $end = $dt->atEndOfMonth();

        static::assertSame(2025, $end->getYear());
        static::assertSame(6, $end->getMonth());
        static::assertSame(30, $end->getDay());
        static::assertSame(23, $end->getHours());
        static::assertSame(59, $end->getMinutes());
        static::assertSame(59, $end->getSeconds());
        static::assertSame(999_999_999, $end->getNanoseconds());
    }

    public function testAtEndOfMonthFebruary(): void
    {
        // Non-leap year: February has 28 days
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 2, 10);
        $end = $dt->atEndOfMonth();

        static::assertSame(28, $end->getDay());

        // Leap year: February has 29 days
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 2, 10);
        $end = $dt->atEndOfMonth();

        static::assertSame(29, $end->getDay());
    }

    public function testAtEndOfMonthDecember(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 12, 1);
        $end = $dt->atEndOfMonth();

        static::assertSame(31, $end->getDay());
        static::assertSame(23, $end->getHours());
        static::assertSame(59, $end->getMinutes());
        static::assertSame(59, $end->getSeconds());
        static::assertSame(999_999_999, $end->getNanoseconds());
    }

    public function testAtStartOfDayPreservesTimezone(): void
    {
        $dt = DateTime::fromParts(Timezone::AmericaNewYork, 2025, 6, 15, 14, 30);
        $start = $dt->atStartOfDay();

        static::assertSame(Timezone::AmericaNewYork, $start->getTimezone());
    }

    public function testAtStartOfYear(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 14, 30, 45, 123);
        $start = $dt->atStartOfYear();

        static::assertSame(2024, $start->getYear());
        static::assertSame(1, $start->getMonth());
        static::assertSame(1, $start->getDay());
        static::assertSame(0, $start->getHours());
        static::assertSame(0, $start->getMinutes());
        static::assertSame(0, $start->getSeconds());
        static::assertSame(0, $start->getNanoseconds());
    }

    public function testAtEndOfYear(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 15, 14, 30, 45, 123);
        $end = $dt->atEndOfYear();

        static::assertSame(2024, $end->getYear());
        static::assertSame(12, $end->getMonth());
        static::assertSame(31, $end->getDay());
        static::assertSame(23, $end->getHours());
        static::assertSame(59, $end->getMinutes());
        static::assertSame(59, $end->getSeconds());
        static::assertSame(999_999_999, $end->getNanoseconds());
    }

    public function testAtStartOfWeekMonday(): void
    {
        // 2024-06-10 is a Monday
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 10, 14, 30);
        $start = $dt->atStartOfWeek();

        static::assertSame(2024, $start->getYear());
        static::assertSame(6, $start->getMonth());
        static::assertSame(10, $start->getDay());
        static::assertSame(0, $start->getHours());
    }

    public function testAtStartOfWeekWednesday(): void
    {
        // 2024-06-12 is a Wednesday
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 12, 14, 30);
        $start = $dt->atStartOfWeek();

        static::assertSame(2024, $start->getYear());
        static::assertSame(6, $start->getMonth());
        static::assertSame(10, $start->getDay());
        static::assertSame(0, $start->getHours());
    }

    public function testAtStartOfWeekSunday(): void
    {
        // 2024-06-16 is a Sunday
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 16, 14, 30);
        $start = $dt->atStartOfWeek();

        static::assertSame(2024, $start->getYear());
        static::assertSame(6, $start->getMonth());
        static::assertSame(10, $start->getDay());
        static::assertSame(0, $start->getHours());
    }

    public function testAtEndOfWeekMonday(): void
    {
        // 2024-06-10 is a Monday
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 10, 14, 30);
        $end = $dt->atEndOfWeek();

        static::assertSame(2024, $end->getYear());
        static::assertSame(6, $end->getMonth());
        static::assertSame(16, $end->getDay());
        static::assertSame(23, $end->getHours());
        static::assertSame(59, $end->getMinutes());
        static::assertSame(59, $end->getSeconds());
        static::assertSame(999_999_999, $end->getNanoseconds());
    }

    public function testAtEndOfWeekSunday(): void
    {
        // 2024-06-16 is a Sunday
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 6, 16, 14, 30);
        $end = $dt->atEndOfWeek();

        static::assertSame(2024, $end->getYear());
        static::assertSame(6, $end->getMonth());
        static::assertSame(16, $end->getDay());
        static::assertSame(23, $end->getHours());
    }

    /**
     * @return iterable<string, array{Timezone}>
     */
    public static function provideTimezonesForSubSecondFormatting(): iterable
    {
        yield 'UTC' => [Timezone::UTC];
        yield 'Europe/London' => [Timezone::EuropeLondon];
        yield 'America/New_York' => [Timezone::AmericaNewYork];
        yield 'Asia/Kathmandu' => [Timezone::AsiaKathmandu];
        yield 'Australia/Lord_Howe' => [Timezone::AustraliaLordHowe];
        yield 'Pacific/Kiritimati' => [Timezone::PacificKiritimati];
        yield '-03:30' => [Timezone::Minus0330];
    }

    #[DataProvider('provideTimezonesForSubSecondFormatting')]
    public function testFormattingTheEndOfAPeriodDoesNotRoundUpToTheNextOne(Timezone $timezone): void
    {
        $dt = DateTime::fromParts($timezone, 2024, 1, 15, 12);

        $endOfYear = $dt->atEndOfYear();
        static::assertSame('2024-12-31', $endOfYear->format(FormatPattern::SqlDate));
        static::assertSame('2024-12-31 23:59:59.999', $endOfYear->format('yyyy-MM-dd HH:mm:ss.SSS'));
        static::assertSame(366, $endOfYear->getDayOfYear());
        static::assertSame('31/12/2024, 23:59:59', $endOfYear->toString(
            DateStyle::Short,
            TimeStyle::Medium,
            locale: Locale::EnglishUnitedKingdom,
        ));

        $endOfJanuary = $dt->atEndOfMonth();
        static::assertSame(31, $endOfJanuary->getDayOfYear());
        static::assertSame('2024-01-31 23:59:59', $endOfJanuary->format(FormatPattern::SqlDateTime));

        // 2024-06-16 is a Sunday, the last day of ISO week 24.
        $endOfSunday = DateTime::fromParts($timezone, 2024, 6, 16, 12)->atEndOfDay();
        static::assertSame(Weekday::Sunday, $endOfSunday->getWeekday());
        static::assertSame([2024, 24], $endOfSunday->getISOWeekNumber());
        static::assertSame('2024-W24-7', $endOfSunday->format(FormatPattern::IsoWeekDate));
        static::assertTrue($endOfSunday->atEndOfWeek()->equals($endOfSunday));
    }

    #[DataProvider('provideTimezonesForSubSecondFormatting')]
    public function testFormattingSubSecondInstantsBeforeTheEpoch(Timezone $timezone): void
    {
        // 1950-01-15 is a Sunday.
        $dt = DateTime::fromParts($timezone, 1950, 1, 15, 12);

        $endOfYear = $dt->atEndOfYear();
        static::assertSame('1950-12-31', $endOfYear->format(FormatPattern::SqlDate));
        static::assertSame('1950-12-31 23:59:59.999', $endOfYear->format('yyyy-MM-dd HH:mm:ss.SSS'));
        static::assertSame(365, $endOfYear->getDayOfYear());

        static::assertSame(31, $dt->atEndOfMonth()->getDayOfYear());

        $endOfSunday = $dt->atEndOfDay();
        static::assertSame(Weekday::Sunday, $endOfSunday->getWeekday());
        static::assertTrue($endOfSunday->atEndOfWeek()->equals($endOfSunday));
    }

    public function testFormattingTheEndOfADayWithADaylightSavingTimeTransition(): void
    {
        $pattern = 'yyyy-MM-dd HH:mm:ss.SSS xxx';

        // London springs forward on 2024-03-31, and falls back on 2024-10-27.
        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 3, 31, 12)->atEndOfDay();
        static::assertSame('2024-03-31 23:59:59.999 +01:00', $dt->format($pattern));
        static::assertSame(Weekday::Sunday, $dt->getWeekday());

        $dt = DateTime::fromParts(Timezone::EuropeLondon, 2024, 10, 27, 12)->atEndOfDay();
        static::assertSame('2024-10-27 23:59:59.999 +00:00', $dt->format($pattern));
        static::assertSame(301, $dt->getDayOfYear());

        // America/Nuuk skips 23:00-00:00 on 2024-03-30, so that day ends just before the transition; rounding
        // up would land on the transition itself, midnight on the next day.
        $dt = DateTime::fromParts(Timezone::AmericaNuuk, 2024, 3, 30, 12)->atEndOfDay();
        static::assertSame('2024-03-30 22:59:59.999 -02:00', $dt->format($pattern));
        static::assertSame(Weekday::Saturday, $dt->getWeekday());

        // Cuba repeats 00:00-01:00 on 2024-11-03, so the day before ends just before the first midnight.
        $dt = DateTime::fromParts(Timezone::AmericaHavana, 2024, 11, 2, 12)->atEndOfDay();
        static::assertSame('2024-11-02 23:59:59.999 -04:00', $dt->format($pattern));
        static::assertSame(Weekday::Saturday, $dt->getWeekday());
    }

    public function testPlusZeroPeriodReturnsSameInstance(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 6, 15, 12, 0, 0);

        $result = $dt->plus(Period::zero());

        static::assertSame($dt, $result);
    }

    public function testMinusZeroPeriodReturnsSameInstance(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 6, 15, 12, 0, 0);

        $result = $dt->minus(Period::zero());

        static::assertSame($dt, $result);
    }

    public function testPlusPeriodDaysOnly(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 3, 10, 14, 30, 0);

        $result = $dt->plus(Period::days(5));

        static::assertSame(2025, $result->getYear());
        static::assertSame(3, $result->getMonth());
        static::assertSame(15, $result->getDay());
        static::assertSame(14, $result->getHours());
        static::assertSame(30, $result->getMinutes());
    }

    public function testMinusPeriodCausesNegativeMonthWrap(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 3, 15, 12, 0, 0);

        $result = $dt->minus(Period::months(15));

        static::assertSame(2023, $result->getYear());
        static::assertSame(12, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testPlusPeriodMonthsOnlyNoSeconds(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 3, 15, 10, 30, 0);

        $result = $dt->plus(Period::months(2));

        static::assertSame(2025, $result->getYear());
        static::assertSame(5, $result->getMonth());
        static::assertSame(15, $result->getDay());
        static::assertSame(10, $result->getHours());
        static::assertSame(30, $result->getMinutes());
    }

    public function testPlusPeriodDaysOnlyNoMonths(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 3, 15, 10, 30, 0);

        $result = $dt->plus(Period::days(10));

        static::assertSame(2025, $result->getYear());
        static::assertSame(3, $result->getMonth());
        static::assertSame(25, $result->getDay());
        static::assertSame(10, $result->getHours());
        static::assertSame(30, $result->getMinutes());
    }

    public function testMinusMonthsCausesMonthToReachZero(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 15, 12, 0, 0);

        $result = $dt->minusMonths(1);

        static::assertSame(2024, $result->getYear());
        static::assertSame(12, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testMinusMonthsCausesMonthToReachExactlyZero(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 10, 0, 0, 0);

        $result = $dt->minusMonths(1);

        static::assertSame(2024, $result->getYear());
        static::assertSame(12, $result->getMonth());
        static::assertSame(10, $result->getDay());
    }

    public function testPlusPeriodWithNegativeMonthResult(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2025, 1, 15, 12, 0, 0);

        $result = $dt->minus(Period::months(2));

        static::assertSame(2024, $result->getYear());
        static::assertSame(11, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }

    public function testPlusPeriodExactlyTwelveMonths(): void
    {
        $dt = DateTime::fromParts(Timezone::UTC, 2024, 1, 15, 10, 0, 0);

        $result = $dt->plus(Period::months(12));

        static::assertSame(2025, $result->getYear());
        static::assertSame(1, $result->getMonth());
        static::assertSame(15, $result->getDay());
    }
}
