<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use Psl\DateTime\Exception;
use Psl\DateTime\Timezone;

use function sprintf;

/**
 * Creates a Gregorian {@see IntlCalendar} set to a wall-clock date and time in the given timezone.
 *
 * Only the years -5838268 to 5368709 are accepted: ICU cannot compute the time of a date after the year
 * 5368709 (it reports an error, or computes a wrong time with older versions), and it clamps instants
 * before the year -5838268 to its earliest supported instant.
 *
 * @throws Exception\OverflowException If the year is greater than 5368709.
 * @throws Exception\UnderflowException If the year is less than -5838268.
 *
 * @internal
 *
 * @psalm-mutation-free
 *
 * @infection-ignore-all
 */
function create_intl_calendar_from_date_time(
    Timezone $timezone,
    int $year,
    int $month,
    int $day,
    int $hours,
    int $minutes,
    int $seconds,
): IntlCalendar {
    if ($year > 5_368_709) {
        throw new Exception\OverflowException(sprintf(
            'The year %d is after 5368709, the latest supported year.',
            $year,
        ));
    }

    if ($year < -5_838_268) {
        throw new Exception\UnderflowException(sprintf(
            'The year %d is before -5838268, the earliest supported year.',
            $year,
        ));
    }

    $calendar = namespace\create_intl_calendar($timezone);

    // The calendar starts at the current time; clear it so no field (most notably the milliseconds)
    // leaks from "now" into the computed time.
    $calendar->clear();
    $calendar->setDateTime($year, $month - 1, $day, $hours, $minutes, $seconds);
    // `setDateTime()` sets the era-relative `FIELD_YEAR`; PSL uses astronomical year numbering
    // (1 BC is 0, 2 BC is -1), which is ICU's extended year. The most recently set year field wins.
    $calendar->set(IntlCalendar::FIELD_EXTENDED_YEAR, $year);

    return $calendar;
}
