<?php

declare(strict_types=1);

namespace Psl\DateTime\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use IntlTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\DateTime;
use Psl\DateTime\Exception\InvalidArgumentException;
use Psl\DateTime\Exception\RuntimeException;
use Psl\DateTime\Timestamp;
use Psl\DateTime\Timezone;

use function date_default_timezone_set;
use function in_array;
use function intdiv;
use function sprintf;
use function str_starts_with;

use const Psl\DateTime\MILLISECONDS_PER_SECOND;

final class TimezoneTest extends TestCase
{
    use DateTimeTestTrait;

    public function testDefault(): void
    {
        /**
         * @see DateTimeTestTrait::setUp() for the default timezone set to Europe/London
         */
        static::assertSame(Timezone::EuropeLondon, Timezone::default());
    }

    /**
     * @return iterable<array{string, Timezone}>
     */
    public static function provideAliases(): iterable
    {
        yield ['US/Eastern', Timezone::AmericaNewYork];
        yield ['Asia/Calcutta', Timezone::AsiaKolkata];
        yield ['America/Buenos_Aires', Timezone::AmericaArgentinaBuenosAires];
        yield ['Europe/Kiev', Timezone::EuropeKiev];
        yield ['Etc/UTC', Timezone::UTC];
        yield ['Universal', Timezone::UTC];
        yield ['GMT', Timezone::UTC];
        yield ['Etc/GMT+5', Timezone::Minus0500];
        yield ['Etc/GMT-14', Timezone::Plus1400];
    }

    public function testDefaultResolvesLegacyFixedOffsetZones(): void
    {
        // "EST" is a link to America/Panama since tzdata 2024b, and its own -05:00 zone before that.
        date_default_timezone_set('EST');

        $timezone = Timezone::default();

        static::assertSame(-18_000, (int) $timezone->getRawOffset()->getTotalSeconds());
        static::assertFalse($timezone->usesDaylightSavingTime());
    }

    #[DataProvider('provideAliases')]
    public function testDefaultResolvesAliases(string $identifier, Timezone $expected): void
    {
        date_default_timezone_set($identifier);

        static::assertSame($expected, Timezone::default());
        static::assertSame($expected, DateTime::now()->getTimezone());
    }

    /**
     * @return iterable<array{string, Timezone}>
     */
    public static function provideSystemVNames(): iterable
    {
        yield ['CET', Timezone::EuropeBrussels];
        yield ['MET', Timezone::EuropeBrussels];
        yield ['EET', Timezone::EuropeAthens];
        yield ['WET', Timezone::EuropeLisbon];
        yield ['CST6CDT', Timezone::AmericaChicago];
        yield ['EST5EDT', Timezone::AmericaNewYork];
        yield ['MST7MDT', Timezone::AmericaDenver];
        yield ['PST8PDT', Timezone::AmericaLosAngeles];
    }

    #[DataProvider('provideSystemVNames')]
    public function testDefaultResolvesSystemVNamesWhateverTheIcuData(string $identifier, Timezone $expected): void
    {
        // Links since tzdata 2024b; ICU data that predates it (e.g. ICU 74) has them as zones of their own.
        date_default_timezone_set($identifier);

        static::assertSame($expected, Timezone::default());
        static::assertSame($expected, Timezone::fromIntl(IntlTimeZone::createTimeZone($identifier)));
    }

    public function testDefaultResolvesEveryIdentifierOfTheTimezoneDatabase(): void
    {
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $identifier) {
            $intlTimezone = IntlTimeZone::createTimeZone($identifier);
            if ('Etc/Unknown' === $intlTimezone->getID()) {
                // Newer than the ICU data of this build; see testDefaultRejectsAZoneUnknownToIcu().
                continue;
            }

            date_default_timezone_set($identifier);
            $timezone = Timezone::default();

            // The resolved zone has the offsets ICU gives the identifier, in winter and in summer (2021-01-15 and
            // 2021-07-15). PHP's own offsets are no reference: PHP may read another tzdata release than the ICU
            // data of this build (Ubuntu's PHP reads the system tzdata, while its ICU 74 has tzdata 2023c), and
            // tzdata 2023d corrected the past offsets of Antarctica/Casey and Antarctica/Vostok.
            foreach ([1_610_712_000, 1_626_350_400] as $seconds) {
                $intlTimezone->getOffset($seconds * MILLISECONDS_PER_SECOND, false, $rawOffset, $dstOffset);

                static::assertSame(
                    intdiv($rawOffset + $dstOffset, MILLISECONDS_PER_SECOND),
                    (int) $timezone->getOffset(Timestamp::fromParts($seconds))->getTotalSeconds(),
                    sprintf('"%s" resolved to "%s".', $identifier, $timezone->value),
                );
            }
        }
    }

    public function testDefaultRejectsAZoneUnknownToIcu(): void
    {
        if (!in_array('America/Coyhaique', DateTimeZone::listIdentifiers(), true)) {
            static::markTestSkipped('The timezone database of this build does not know "America/Coyhaique".');
        }

        date_default_timezone_set('America/Coyhaique');

        if ('Etc/Unknown' !== IntlTimeZone::createTimeZone('America/Coyhaique')->getID()) {
            static::assertSame(Timezone::AmericaCoyhaique, Timezone::default());

            return;
        }

        // The ICU data of this build (before ICU 78) cannot compute anything in this zone.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The default timezone "America/Coyhaique" is not supported.');

        Timezone::default();
    }

    public function testUsingAZoneUnknownToIcuThrowsARuntimeException(): void
    {
        $unknown = null;
        foreach (Timezone::cases() as $timezone) {
            // Offsets are always known; Kyiv and Kanton fall back to their previous names on older ICU data.
            if (
                str_starts_with($timezone->value, '+')
                || str_starts_with($timezone->value, '-')
                || Timezone::EuropeKyiv === $timezone
                || Timezone::PacificKanton === $timezone
            ) {
                continue;
            }

            if ('Etc/Unknown' === IntlTimeZone::createTimeZone($timezone->value)?->getID()) {
                $unknown = $timezone;
                break;
            }
        }

        if (null === $unknown) {
            static::markTestSkipped('The ICU data of this build knows every zone.');
        }

        try {
            $unknown->getRawOffset();

            static::fail(sprintf('Expected "%s" to be unknown to the ICU data of this build.', $unknown->value));
        } catch (RuntimeException $exception) {
            static::assertStringContainsString(
                sprintf('does not know the timezone "%s"', $unknown->value),
                $exception->getMessage(),
            );
        }

        $this->expectException(RuntimeException::class);
        $unknown->toIntl();
    }

    #[DataProvider('provideAliases')]
    public function testFromStdlibAndFromIntlResolveAliases(string $identifier, Timezone $expected): void
    {
        static::assertSame($expected, Timezone::fromStdlib(new DateTimeZone($identifier)));
        static::assertSame($expected, Timezone::fromIntl(IntlTimeZone::createTimeZone($identifier)));

        $datetime = DateTime::fromStdlib(new DateTimeImmutable('2024-01-15 12:00:00', new DateTimeZone($identifier)));
        static::assertSame($expected, $datetime->getTimezone());
        static::assertSame(12, $datetime->getHours());

        $calendar = IntlCalendar::createInstance($identifier);
        $calendar->setTime(1_705_320_000_000.0);
        static::assertSame($expected, DateTime::fromIntl($calendar)->getTimezone());
    }

    public function testFromStdlibResolvesOffsetsAndAbbreviations(): void
    {
        static::assertSame(Timezone::UTC, Timezone::fromStdlib(new DateTimeZone('+00:00')));
        static::assertSame(Timezone::UTC, Timezone::fromStdlib(new DateTimeZone('Z')));
        static::assertSame(Timezone::Plus0530, Timezone::fromStdlib(new DateTimeZone('+05:30')));
        static::assertSame(Timezone::Plus0200, Timezone::fromStdlib(new DateTimeZone('CEST')));
    }

    /**
     * @return iterable<array{string, Timezone}>
     */
    public static function provideAbbreviations(): iterable
    {
        // ICU reads most of these as region zones, with other offsets or daylight saving time.
        yield ['CET', Timezone::Plus0100];
        yield ['CEST', Timezone::Plus0200];
        yield ['PST', Timezone::Minus0800];
        yield ['IST', Timezone::Plus0200]; // PHP reads "IST" as Israel Standard Time.
        yield ['AST', Timezone::Minus0400];
        yield ['BST', Timezone::Plus0100];
        yield ['EST', Timezone::Minus0500];
        yield ['WET', Timezone::UTC];
    }

    #[DataProvider('provideAbbreviations')]
    public function testFromStdlibUsesTheFixedOffsetOfAnAbbreviation(string $abbreviation, Timezone $expected): void
    {
        $timezone = new DateTimeZone($abbreviation);

        static::assertSame($expected, Timezone::fromStdlib($timezone));

        $value = new DateTimeImmutable('2024-07-01 12:00:00', $timezone);
        $datetime = DateTime::fromStdlib($value);

        static::assertSame($expected, $datetime->getTimezone());
        static::assertSame([2024, 7, 1, 12, 0, 0, 0], $datetime->getParts());
        static::assertSame($value->getTimestamp(), $datetime->getTimestamp()->getSeconds());
    }

    public function testUnsupportedTimezonesThrowAPslException(): void
    {
        foreach (['+05:17', '+05:30:30'] as $offset) {
            try {
                Timezone::fromStdlib(new DateTimeZone($offset));
                static::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                static::assertSame('The timezone "' . $offset . '" is not supported.', $e->getMessage());
            }
        }

        try {
            Timezone::fromIntl(IntlTimeZone::createTimeZone('GMT+05:17'));
            static::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            static::assertSame('The timezone "GMT+05:17" is not supported.', $e->getMessage());
        }
    }

    /**
     * @return iterable<array{string, Timezone, int}>
     */
    public static function provideCurrentIanaIdentifiers(): iterable
    {
        yield ['Europe/Kyiv', Timezone::EuropeKyiv, 7_200];
        yield ['America/Ciudad_Juarez', Timezone::AmericaCiudadJuarez, -25_200];
        yield ['Pacific/Kanton', Timezone::PacificKanton, 46_800];
        yield ['America/Coyhaique', Timezone::AmericaCoyhaique, -10_800];
    }

    #[DataProvider('provideCurrentIanaIdentifiers')]
    public function testCurrentIanaIdentifiersAreSupported(string $identifier, Timezone $expected, int $rawOffset): void
    {
        static::assertSame($expected, Timezone::from($identifier));

        if (!in_array($identifier, DateTimeZone::listIdentifiers(), true)) {
            static::markTestSkipped('The timezone database of this build does not know "' . $identifier . '" yet.');
        }

        if ('Etc/Unknown' === IntlTimeZone::createTimeZone($identifier)->getID()) {
            static::markTestSkipped('The ICU data of this build does not know "' . $identifier . '" yet.');
        }

        static::assertSame($expected, Timezone::fromStdlib(new DateTimeZone($identifier)));
        static::assertSame($expected, Timezone::fromIntl(IntlTimeZone::createTimeZone($identifier)));
        static::assertSame($rawOffset, (int) $expected->getRawOffset()->getTotalSeconds());
    }

    public function testEveryCanonicalIdentifierIsACase(): void
    {
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            static::assertNotNull(Timezone::tryFrom($identifier), sprintf('"%s" is not a Timezone case.', $identifier));
        }
    }

    public function testRenamedZonesHaveTheRulesOfTheirPreviousNames(): void
    {
        // Older ICU data only knows the previous names; the renamed zones fall back to them there.
        static::assertTrue(Timezone::EuropeKyiv->hasTheSameRulesAs(Timezone::EuropeKiev));
        static::assertTrue(Timezone::PacificKanton->hasTheSameRulesAs(Timezone::PacificEnderbury));
    }

    public function testGetOffset(): void
    {
        $temporal = Timestamp::fromParts(seconds: 1_716_956_903);

        static::assertSame(3600., Timezone::EuropeLondon->getOffset($temporal)->getTotalSeconds());
        static::assertSame(-14_400., Timezone::AmericaNewYork->getOffset($temporal)->getTotalSeconds());
        static::assertSame(28_800., Timezone::AsiaShanghai->getOffset($temporal)->getTotalSeconds());
        static::assertSame(12_600., Timezone::Plus0330->getOffset($temporal)->getTotalSeconds());
        static::assertSame(-12_600., Timezone::Minus0330->getOffset($temporal)->getTotalSeconds());
        static::assertSame(3600., Timezone::Plus0100->getOffset($temporal)->getTotalSeconds());
        static::assertSame(-3600., Timezone::Minus0100->getOffset($temporal)->getTotalSeconds());

        // Local
        $brussels = Timezone::EuropeBrussels;
        date_default_timezone_set($brussels->value);

        $summer = DateTime::fromParts($brussels, 2024, 3, 31, 3);

        static::assertSame(2., $brussels->getOffset($summer)->getTotalHours());
        static::assertSame(1., $brussels->getOffset($summer, local: true)->getTotalHours());
    }

    #[DataProvider('provideRawOffsetData')]
    public function testRawOffset(Timezone $timezone, int $expected): void
    {
        static::assertSame($expected, (int) $timezone->getRawOffset()->getTotalSeconds());
    }

    public function testUsesDaylightSavingTime(): void
    {
        static::assertTrue(Timezone::AmericaNewYork->usesDaylightSavingTime());
        static::assertTrue(Timezone::EuropeLondon->usesDaylightSavingTime());
        static::assertFalse(Timezone::AsiaShanghai->usesDaylightSavingTime());
    }

    public function testGetDaylightSavingTimeSavings(): void
    {
        static::assertSame(3600., Timezone::AmericaNewYork->getDaylightSavingTimeSavings()->getTotalSeconds());
        static::assertSame(3600., Timezone::EuropeLondon->getDaylightSavingTimeSavings()->getTotalSeconds());
        static::assertSame(0., Timezone::AsiaShanghai->getDaylightSavingTimeSavings()->getTotalSeconds());
    }

    public function testHasTheSameRulesAs(): void
    {
        static::assertTrue(Timezone::AmericaNewYork->hasTheSameRulesAs(Timezone::AmericaNewYork));
        static::assertFalse(Timezone::AmericaNewYork->hasTheSameRulesAs(Timezone::EuropeLondon));
    }

    public function testGetDaylightSavingTimeOffset(): void
    {
        $brussels = Timezone::EuropeBrussels;
        date_default_timezone_set($brussels->value);

        $summer = DateTime::fromParts($brussels, 2024, 3, 31, 3);
        $winter = DateTime::fromParts($brussels, 2024, 10, 27, 2);

        static::assertSame(0., $brussels->getDaylightSavingTimeOffset($winter)->getTotalHours());
        static::assertSame(1., $brussels->getDaylightSavingTimeOffset($winter, local: true)->getTotalHours());
        static::assertSame(1., $brussels->getDaylightSavingTimeOffset($summer)->getTotalHours());
        static::assertSame(0., $brussels->getDaylightSavingTimeOffset($summer, local: true)->getTotalHours());
    }

    public static function provideRawOffsetData(): iterable
    {
        yield [Timezone::EuropeLondon, 0];
        yield [Timezone::AmericaNewYork, -18_000];
        yield [Timezone::AsiaShanghai, 28_800];
    }

    public function testToStdlib(): void
    {
        $tz = Timezone::AmericaNewYork;

        $stdlib = $tz->toStdlib();

        static::assertInstanceOf(DateTimeZone::class, $stdlib);
        static::assertSame('America/New_York', $stdlib->getName());
    }

    public function testFromStdlib(): void
    {
        $stdlib = new DateTimeZone('Europe/Paris');

        $tz = Timezone::fromStdlib($stdlib);

        static::assertSame(Timezone::EuropeParis, $tz);
    }

    public function testStdlibRoundTrip(): void
    {
        $original = Timezone::AsiaShanghai;

        $roundTripped = Timezone::fromStdlib($original->toStdlib());

        static::assertSame($original, $roundTripped);
    }

    public function testToIntl(): void
    {
        $tz = Timezone::EuropeParis;

        $intl = $tz->toIntl();

        static::assertInstanceOf(IntlTimeZone::class, $intl);
        static::assertSame('Europe/Paris', $intl->getID());
    }

    public function testFromIntl(): void
    {
        $intl = IntlTimeZone::createTimeZone('America/New_York');

        $tz = Timezone::fromIntl($intl);

        static::assertSame(Timezone::AmericaNewYork, $tz);
    }

    public function testIntlRoundTrip(): void
    {
        $original = Timezone::UTC;

        $roundTripped = Timezone::fromIntl($original->toIntl());

        static::assertSame($original, $roundTripped);
    }
}
