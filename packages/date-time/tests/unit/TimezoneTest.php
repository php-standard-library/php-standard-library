<?php

declare(strict_types=1);

namespace Psl\DateTime\Tests\Unit;

use DateTimeZone;
use IntlTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\DateTime;
use Psl\DateTime\Timestamp;
use Psl\DateTime\Timezone;

use function date_default_timezone_set;
use function in_array;
use function sprintf;

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
