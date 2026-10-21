<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use Psl\DateTime\DateStyle;
use Psl\DateTime\FormatPattern;
use Psl\DateTime\Timestamp;
use Psl\DateTime\TimeStyle;
use Psl\DateTime\Timezone;
use Psl\Locale\Locale;

use function intdiv;

use const Psl\DateTime\MILLISECONDS_PER_SECOND;
use const Psl\DateTime\NANOSECONDS_PER_MILLISECOND;

/**
 * Formats a timestamp using an ICU pattern, one of the {@see FormatPattern} cases, or date and time styles.
 *
 * The timestamp is formatted to the millisecond, the precision of ICU, truncated toward negative infinity:
 * formatting never moves an instant into the next second, minute, or day.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function format(
    Timestamp $timestamp,
    null|FormatPattern|string $pattern = null,
    null|Timezone $timezone = null,
    null|Locale $locale = null,
    null|DateStyle $dateStyle = null,
    null|TimeStyle $timeStyle = null,
): string {
    // ICU has no "seconds since the epoch" field.
    if (FormatPattern::UnixTimestamp === $pattern) {
        return (string) $timestamp->getSeconds();
    }

    $formatter = namespace\create_intl_date_formatter($dateStyle, $timeStyle, $pattern, $timezone, $locale);

    // A float number of seconds cannot hold the nanoseconds of a present-day instant (doubles around 1.7e9
    // are 2.4e-7 apart), so 23:59:59.999999999 would round up to midnight of the next day. Pass the instant
    // as a calendar instead, which holds it in milliseconds; the formatter only takes the instant from it,
    // and keeps its own timezone and calendar. The nanoseconds are never negative, so dividing them truncates
    // the instant toward negative infinity, before the epoch too.
    $calendar = namespace\create_intl_calendar(Timezone::UTC);
    $calendar->setTime(
        ($timestamp->getSeconds() * MILLISECONDS_PER_SECOND)
        + intdiv($timestamp->getNanoseconds(), NANOSECONDS_PER_MILLISECOND),
    );

    /** @var string */
    return $formatter->format($calendar);
}
