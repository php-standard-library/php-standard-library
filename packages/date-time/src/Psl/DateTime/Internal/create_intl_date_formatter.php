<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use IntlDateFormatter;
use Psl\DateTime\DateStyle;
use Psl\DateTime\FormatPattern;
use Psl\DateTime\TimeStyle;
use Psl\DateTime\Timezone;
use Psl\Locale\Locale;

/**
 * @param null|Locale|string $locale The locale; a string is used as an ICU locale identifier as-is.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function create_intl_date_formatter(
    null|DateStyle $dateStyle = null,
    null|TimeStyle $timeStyle = null,
    null|FormatPattern|string $pattern = null,
    null|Timezone $timezone = null,
    null|Locale|string $locale = null,
): IntlDateFormatter {
    $isoWeekDate = FormatPattern::IsoWeekDate === $pattern;
    if (null === $locale && $pattern instanceof FormatPattern) {
        // Machine-readable formats must not depend on the default locale, which may use other digits
        // (e.g. Arabic-Indic for "ar_EG") or other month and weekday names.
        $locale = match ($pattern) {
            FormatPattern::SimpleDate,
            FormatPattern::American,
            FormatPattern::WeekdayMonthDayYear,
            FormatPattern::FullDateTime,
                => null,
            default => 'en_US_POSIX',
        };
    }

    if (FormatPattern::Http === $pattern || FormatPattern::Cookie === $pattern) {
        // HTTP and cookie dates are always expressed in GMT.
        $timezone = Timezone::UTC;
    }

    if ($pattern instanceof FormatPattern) {
        $pattern = $pattern->value;
    }

    $dateStyle ??= DateStyle::default();
    $timeStyle ??= TimeStyle::default();
    $locale ??= Locale::default();
    $timezone ??= Timezone::default();

    $formatter = new IntlDateFormatter(
        $locale instanceof Locale ? $locale->value : $locale,
        match ($dateStyle) {
            DateStyle::None => IntlDateFormatter::NONE,
            DateStyle::Short => IntlDateFormatter::SHORT,
            DateStyle::Medium => IntlDateFormatter::MEDIUM,
            // @codeCoverageIgnoreStart
            DateStyle::Long => IntlDateFormatter::LONG,
            DateStyle::Full => IntlDateFormatter::FULL,
            // @codeCoverageIgnoreEnd
        },
        match ($timeStyle) {
            TimeStyle::None => IntlDateFormatter::NONE,
            TimeStyle::Short => IntlDateFormatter::SHORT,
            TimeStyle::Medium => IntlDateFormatter::MEDIUM,
            TimeStyle::Long => IntlDateFormatter::LONG,
            TimeStyle::Full => IntlDateFormatter::FULL,
        },
        namespace\to_intl_timezone($timezone),
        IntlDateFormatter::GREGORIAN,
        $pattern,
    );

    if ($isoWeekDate) {
        // Week numbering depends on the locale (e.g. en_US weeks start on Sunday); ISO 8601 weeks start on
        // Monday, and the first week of the year is the one with at least four days in that year.
        /** @var IntlCalendar $calendar */
        $calendar = $formatter->getCalendarObject();
        $calendar->setFirstDayOfWeek(IntlCalendar::DOW_MONDAY);
        $calendar->setMinimalDaysInFirstWeek(4);
        $formatter->setCalendar($calendar);
    }

    return $formatter;
}
