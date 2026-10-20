<?php

declare(strict_types=1);

namespace Psl\DateTime\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\DateStyle;
use Psl\DateTime\DateTime;
use Psl\DateTime\Timestamp;
use Psl\DateTime\TimeStyle;
use Psl\DateTime\Timezone;
use Psl\Locale\Locale;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function defined;
use function file_get_contents;
use function preg_match_all;
use function sprintf;
use function version_compare;

use const INTL_ICU_VERSION;
use const PREG_SET_ORDER;

final class DocblockExamplesTest extends TestCase
{
    use DateTimeTestTrait;

    public function testDocblocksOnlyReferenceExistingSymbols(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            __DIR__ . '/../../src/Psl/DateTime',
            FilesystemIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $content = (string) file_get_contents($path);

            static::assertDoesNotMatchRegularExpression('/\bFormat(?:Date|Time)Style\b/', $content, $path);

            $matches = [];
            preg_match_all(
                '/\b(Timezone|DateStyle|TimeStyle|FormatPattern|SecondsStyle|Month|Weekday|Era|Meridiem)::(\w+)\b(?!\s*\()/',
                $content,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $enum, $case]) {
                if ('class' === $case) {
                    continue;
                }

                static::assertTrue(
                    defined('Psl\\DateTime\\' . $enum . '::' . $case),
                    sprintf('%s references "%s::%s", which does not exist.', $path, $enum, $case),
                );
            }
        }
    }

    public function testParseExamples(): void
    {
        $rawString = '2023-03-15 12:00:00';

        $parsedDatetime = DateTime::parse($rawString, 'yyyy-MM-dd HH:mm:ss', Timezone::UTC, Locale::English);
        $parsedTimestamp = Timestamp::parse($rawString, 'yyyy-MM-dd HH:mm:ss', Timezone::UTC, Locale::English);

        static::assertSame('2023-03-15T12:00:00+00:00', $parsedDatetime->toRfc3339());
        static::assertSame(1_678_881_600, $parsedTimestamp->getSeconds());
    }

    public function testFromStringExamples(): void
    {
        // The "at" between the date and the time is the wording of CLDR 42 (ICU 72) and newer.
        if (version_compare(INTL_ICU_VERSION, '72.1', '<')) {
            static::markTestSkipped('The example uses the long date-time wording of ICU 72 and newer.');
        }

        $rawString = 'March 15, 2023 at 12:00 PM';

        $datetime = DateTime::fromString($rawString, DateStyle::Long, TimeStyle::Short, Timezone::UTC, Locale::English);
        $timestamp = Timestamp::fromString(
            $rawString,
            DateStyle::Long,
            TimeStyle::Short,
            Timezone::UTC,
            Locale::English,
        );

        static::assertSame('2023-03-15T12:00:00+00:00', $datetime->toRfc3339());
        static::assertSame(1_678_881_600, $timestamp->getSeconds());
    }
}
