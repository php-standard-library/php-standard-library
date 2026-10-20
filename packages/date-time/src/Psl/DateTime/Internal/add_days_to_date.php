<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use Psl\DateTime\Exception;
use Psl\DateTime\Timezone;

/**
 * Adds a number of calendar days to a date, without involving any time of day or timezone.
 *
 * @param int<1, 12> $month
 * @param int<1, 31> $day
 *
 * @throws Exception\OverflowException If the year, or the resulting date, is after the latest supported date.
 * @throws Exception\UnderflowException If the year, or the resulting date, is before the earliest supported date.
 *
 * @return array{int, int<1, 12>, int<1, 31>} The resulting year, month, and day.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function add_days_to_date(int $year, int $month, int $day, int $days): array
{
    $calendar = namespace\create_intl_calendar_from_date_time(Timezone::UTC, $year, $month, $day, 0, 0, 0);

    // Count in Julian days: `IntlCalendar::add()` only takes a 32-bit amount, which does not cover the
    // supported range, and silently clamps a result that is out of ICU's range.
    $julianDay = (int) $calendar->get(IntlCalendar::FIELD_JULIAN_DAY) + $days;

    // The Julian days of 5368709-12-31 and -5838268-01-01, the latest and earliest supported dates.
    if ($julianDay > 1_962_602_121) {
        throw new Exception\OverflowException('The resulting date is after 5368709-12-31, the latest supported date.');
    }

    if ($julianDay < -2_130_706_329) {
        throw new Exception\UnderflowException(
            'The resulting date is before -5838268-01-01, the earliest supported date.',
        );
    }

    $calendar->clear();
    $calendar->set(IntlCalendar::FIELD_JULIAN_DAY, $julianDay);

    /** @var array{int, int<1, 12>, int<1, 31>} */
    return [
        $calendar->get(IntlCalendar::FIELD_EXTENDED_YEAR),
        $calendar->get(IntlCalendar::FIELD_MONTH) + 1,
        $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH),
    ];
}
