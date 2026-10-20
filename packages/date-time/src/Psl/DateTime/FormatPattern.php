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
 * When no locale is given, the machine-readable patterns (every case except {@see FormatPattern::SimpleDate},
 * {@see FormatPattern::American}, {@see FormatPattern::WeekdayMonthDayYear}, and
 * {@see FormatPattern::FullDateTime}) are formatted and parsed with the "en_US_POSIX" locale instead of the
 * default locale, so they always use ASCII digits and English month and weekday names.
 *
 * The year field `yyyy` of every case is formatted and parsed as the astronomical year (ICU's `uuuu`), as
 * returned by {@see DateTimeInterface::getYear()}: 1 BC is "0000", 2 BC is "-0001", and so on.
 *
 * @api
 */
enum FormatPattern: string implements DefaultInterface
{
    case Rfc2822 = 'EEE, dd MMM yyyy HH:mm:ss Z';
    case Iso8601 = 'yyyy-MM-dd\'T\'HH:mm:ss.SSSXXX';
    case Iso8601WithoutMicroseconds = 'yyyy-MM-dd\'T\'HH:mm:ssXXX';
    /**
     * HTTP date (RFC 9110 IMF-fixdate), e.g. "Mon, 15 Jan 2024 12:00:00 GMT".
     *
     * HTTP dates are always in GMT, so this pattern is always formatted and parsed in UTC, whatever the
     * timezone given. When parsing, the trailing "GMT" is not checked: ICU's lenient parser ignores any
     * zone text there.
     */
    case Http = "EEE, dd MMM yyyy HH:mm:ss 'GMT'";

    /**
     * Cookie expiry date (as PHP's `DATE_COOKIE`), e.g. "Monday, 15-Jan-2024 12:00:00 GMT".
     *
     * Like {@see FormatPattern::Http}, this pattern is always formatted and parsed in UTC.
     */
    case Cookie = "EEEE, dd-MMM-yyyy HH:mm:ss 'GMT'";
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
    /**
     * Internet Message Format date (RFC 5322), e.g. "Mon, 15 Jan 2024 13:00:00 +0100".
     */
    case Email = 'EEE, dd MMM yyyy HH:mm:ss xx';
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
