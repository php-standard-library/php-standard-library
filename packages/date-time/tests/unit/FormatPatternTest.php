<?php

declare(strict_types=1);

namespace Psl\DateTime\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\DateTime;
use Psl\DateTime\Exception\ParserException;
use Psl\DateTime\FormatPattern;
use Psl\DateTime\Timestamp;
use Psl\DateTime\Timezone;
use Psl\Locale\Locale;

use function locale_set_default;

final class FormatPatternTest extends TestCase
{
    use DateTimeTestTrait;

    public function testDefault(): void
    {
        static::assertSame(FormatPattern::Iso8601, FormatPattern::default());
    }

    public function testMachineReadablePatternsDoNotDependOnTheDefaultLocale(): void
    {
        $timestamp = Timestamp::fromParts(1_705_320_000, 123_000_000);

        foreach (['ar_EG', 'fa_IR', 'fr_FR', 'de_DE'] as $defaultLocale) {
            locale_set_default($defaultLocale);

            static::assertSame('Mon, 15 Jan 2024 12:00:00 +0000', $timestamp->format(
                FormatPattern::Rfc2822,
                Timezone::UTC,
            ));
            static::assertSame('2024-01-15T12:00:00.123Z', $timestamp->format(FormatPattern::Iso8601, Timezone::UTC));
            static::assertSame('2024-01-15 12:00:00', $timestamp->format(FormatPattern::SqlDateTime, Timezone::UTC));
            static::assertSame(
                '2024-01-15 13:00:00,123',
                DateTime::fromTimestamp($timestamp, Timezone::EuropeParis)->format(FormatPattern::LogTimestamp),
            );

            $parsed = Timestamp::parse('Mon, 15 Jan 2024 12:00:00 +0000', FormatPattern::Rfc2822, Timezone::UTC);
            static::assertSame(1_705_320_000, $parsed->getSeconds());

            // An explicit locale is still honoured.
            static::assertSame('lun., 15 janv. 2024 12:00:00 +0000', $timestamp->format(
                FormatPattern::Rfc2822,
                Timezone::UTC,
                Locale::FrenchFrance,
            ));
        }

        // Display patterns keep following the default locale.
        locale_set_default('fr_FR');
        static::assertSame('lundi, janvier 15, 2024 12:00:00', $timestamp->format(
            FormatPattern::FullDateTime,
            Timezone::UTC,
        ));
    }

    public function testHttpAndCookieDatesAreAlwaysInGmt(): void
    {
        $timestamp = Timestamp::fromParts(1_705_320_000);

        foreach ([Timezone::UTC, Timezone::AsiaTokyo, Timezone::AmericaNewYork] as $timezone) {
            static::assertSame('Mon, 15 Jan 2024 12:00:00 GMT', $timestamp->format(FormatPattern::Http, $timezone));
            static::assertSame(
                'Mon, 15 Jan 2024 12:00:00 GMT',
                DateTime::fromTimestamp($timestamp, $timezone)->format(FormatPattern::Http),
            );
            static::assertSame('Monday, 15-Jan-2024 12:00:00 GMT', $timestamp->format(
                FormatPattern::Cookie,
                $timezone,
            ));

            static::assertSame(
                1_705_320_000,
                Timestamp::parse('Mon, 15 Jan 2024 12:00:00 GMT', FormatPattern::Http, $timezone)->getSeconds(),
            );
            static::assertSame(
                1_705_320_000,
                Timestamp::parse('Monday, 15-Jan-2024 12:00:00 GMT', FormatPattern::Cookie, $timezone)->getSeconds(),
            );
        }

        // The trailing zone text is not checked when parsing (ICU's lenient parser ignores it).
        foreach (['Mon, 15 Jan 2024 12:00:00 UTC', 'Mon, 15 Jan 2024 12:00:00 +0200'] as $rawString) {
            static::assertSame(1_705_320_000, Timestamp::parse($rawString, FormatPattern::Http)->getSeconds());
        }
    }

    public function testEmailDatesUseANumericOffset(): void
    {
        $timestamp = Timestamp::fromParts(1_705_320_000);

        static::assertSame('Mon, 15 Jan 2024 12:00:00 +0000', $timestamp->format(FormatPattern::Email, Timezone::UTC));
        static::assertSame('Mon, 15 Jan 2024 13:00:00 +0100', $timestamp->format(
            FormatPattern::Email,
            Timezone::EuropeParis,
        ));
        static::assertSame(
            1_705_320_000,
            Timestamp::parse('Mon, 15 Jan 2024 07:00:00 -0500', FormatPattern::Email)->getSeconds(),
        );
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function provideUnixTimestamps(): iterable
    {
        yield [0, '0'];
        yield [1_705_320_000, '1705320000'];
        yield [-1, '-1'];
    }

    #[DataProvider('provideUnixTimestamps')]
    public function testUnixTimestamp(int $seconds, string $expected): void
    {
        $timestamp = Timestamp::fromParts($seconds, 500_000_000);

        static::assertSame($expected, $timestamp->format(FormatPattern::UnixTimestamp));
        static::assertSame($expected, $timestamp->format(FormatPattern::UnixTimestamp, Timezone::AsiaTokyo));
        static::assertSame(
            $expected,
            DateTime::fromTimestamp($timestamp, Timezone::AmericaNewYork)->format(FormatPattern::UnixTimestamp),
        );

        static::assertSame($seconds, Timestamp::parse($expected, FormatPattern::UnixTimestamp)->getSeconds());
        static::assertSame(
            $seconds,
            DateTime::parse($expected, FormatPattern::UnixTimestamp, Timezone::AsiaTokyo)->getTimestamp()->getSeconds(),
        );
    }

    public function testUnixTimestampParsingAcceptsLeadingZeros(): void
    {
        static::assertSame(7, Timestamp::parse('007', FormatPattern::UnixTimestamp)->getSeconds());
        static::assertSame(-7, Timestamp::parse('-007', FormatPattern::UnixTimestamp)->getSeconds());
        static::assertSame(42, Timestamp::parse('+0042', FormatPattern::UnixTimestamp)->getSeconds());
        static::assertSame(0, Timestamp::parse('000', FormatPattern::UnixTimestamp)->getSeconds());
    }

    public function testUnixTimestampParsingRejectsNonIntegers(): void
    {
        foreach ([
            '',
            'abc',
            '1.5',
            '12 ',
            '-',
            '0x1F',
            '99999999999999999999',
            '0099999999999999999999',
        ] as $rawString) {
            try {
                Timestamp::parse($rawString, FormatPattern::UnixTimestamp);
                static::fail('Expected a ParserException for "' . $rawString . '".');
            } catch (ParserException $e) {
                static::assertStringContainsString('Unix timestamp', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<array{int, int, int, string}>
     */
    public static function provideJulianDays(): iterable
    {
        yield [2000, 1, 1, '2451545'];
        yield [2024, 1, 15, '2460325'];
        yield [1970, 1, 1, '2440588'];
    }

    #[DataProvider('provideJulianDays')]
    public function testJulianDay(int $year, int $month, int $day, string $expected): void
    {
        $datetime = DateTime::fromParts(Timezone::UTC, $year, $month, $day, 12);

        static::assertSame($expected, $datetime->format(FormatPattern::JulianDay));
    }

    /**
     * @return iterable<array{int, int, int, string}>
     */
    public static function provideIsoWeekDates(): iterable
    {
        yield [2024, 1, 15, '2024-W03-1']; // Monday
        yield [2024, 1, 7, '2024-W01-7']; // Sunday
        yield [2021, 1, 1, '2020-W53-5']; // Friday, in the last week of 2020
        yield [2024, 12, 30, '2025-W01-1']; // Monday, in the first week of 2025
    }

    #[DataProvider('provideIsoWeekDates')]
    public function testIsoWeekDateFollowsIso8601WhateverTheLocale(
        int $year,
        int $month,
        int $day,
        string $expected,
    ): void {
        $datetime = DateTime::fromParts(Timezone::UTC, $year, $month, $day);

        foreach ([Locale::EnglishUnitedStates, Locale::EnglishUnitedKingdom, Locale::English] as $locale) {
            static::assertSame($expected, $datetime->format(FormatPattern::IsoWeekDate, locale: $locale));

            $parsed = DateTime::parse($expected, FormatPattern::IsoWeekDate, Timezone::UTC, $locale);
            static::assertSame([$year, $month, $day], $parsed->getDate());
        }
    }
}
