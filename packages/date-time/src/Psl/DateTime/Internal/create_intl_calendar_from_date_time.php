<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use Psl\DateTime\Timezone;

/**
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
    /**
     * @var IntlCalendar $calendar
     */
    $calendar = IntlCalendar::createInstance(namespace\to_intl_timezone($timezone));

    // The calendar starts at the current time; clear it so no field (most notably the milliseconds)
    // leaks from "now" into the computed time.
    $calendar->clear();
    $calendar->setDateTime($year, $month - 1, $day, $hours, $minutes, $seconds);
    // `setDateTime()` sets the era-relative `FIELD_YEAR`; PSL uses astronomical year numbering
    // (1 BC is 0, 2 BC is -1), which is ICU's extended year. The most recently set year field wins.
    $calendar->set(IntlCalendar::FIELD_EXTENDED_YEAR, $year);

    return $calendar;
}
