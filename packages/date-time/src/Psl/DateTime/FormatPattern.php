<?php

declare(strict_types=1);

namespace Psl\DateTime;

use Override;
use Psl\Default\DefaultInterface;

/**
 * An enum of common date pattern strings.
 *
 * This enum provides a collection of standardized date pattern strings for various protocols
 * and standards, such as RFC 2822, ISO 8601, HTTP headers, and more.
 *
 * @api
 */
enum FormatPattern: string implements DefaultInterface
{
    case Rfc2822 = 'EEE, dd MMM yyyy HH:mm:ss Z';
    case Iso8601 = 'yyyy-MM-dd\'T\'HH:mm:ss.SSSXXX';
    case Iso8601WithoutMicroseconds = 'yyyy-MM-dd\'T\'HH:mm:ssXXX';
    case Http = 'EEE, dd MMM yyyy HH:mm:ss zzz';
    case Cookie = 'EEEE, dd-MMM-yyyy HH:mm:ss zzz';
    case SqlDate = 'yyyy-MM-dd';
    case SqlDateTime = 'yyyy-MM-dd HH:mm:ss';
    case XmlRpc = 'yyyyMMdd\'T\'HH:mm:ss';
    /**
     * ISO 8601 week date, e.g. "2024-W03-1" for Monday 2024-01-15.
     *
     * The week-numbering year, week, and weekday always follow ISO 8601 (weeks start on Monday, and week 1
     * is the week with the year's first Thursday), whatever the locale.
     */
    case IsoWeekDate = "YYYY-'W'ww-e";
    case IsoOrdinalDate = 'yyyy-DDD';

    /**
     * Julian day number, e.g. "2460325" for 2024-01-15.
     *
     * ICU's `g` field counts days from local midnight rather than from noon UTC.
     */
    case JulianDay = 'g';
    case Rfc3339 = 'yyyy-MM-dd\'T\'HH:mm:ss.SSSZZZZZ';
    case Rfc3339WithoutMicroseconds = 'yyyy-MM-dd\'T\'HH:mm:ssZZZZZ';
    /**
     * Seconds since the Unix epoch, e.g. "1705320000".
     *
     * ICU has no such field (in an ICU pattern, `U` is the cyclic year name), so PSL handles this case
     * itself: formatting prints the whole seconds, and parsing accepts an optionally signed integer.
     * This only applies to the enum case, not to the raw 'U' pattern string.
     */
    case UnixTimestamp = 'U';
    case SimpleDate = 'dd/MM/yyyy';
    case American = 'MM/dd/yyyy';
    case WeekdayMonthDayYear = 'EEE, MMM dd, yyyy';
    case Email = 'EEE, dd MMM yyyy HH:mm:ss ZZZZ';
    case LogTimestamp = 'yyyy-MM-dd HH:mm:ss,SSS';
    case FullDateTime = 'EEEE, MMMM dd, yyyy HH:mm:ss';

    /**
     * Returns the default date format pattern.
     *
     * This method implements the DefaultInterface, providing a standard way to access the default enum case.
     * The ISO 8601 format is returned as the default, which is widely used and recognized.
     *
     * @return FormatPattern::Iso8601 The default date format pattern.
     *
     * @pure
     */
    #[Override]
    public static function default(): static
    {
        return static::Iso8601;
    }
}
