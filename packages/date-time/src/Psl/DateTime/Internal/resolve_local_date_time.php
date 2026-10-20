<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use Psl\DateTime\Exception;
use Psl\DateTime\Timezone;

use const Psl\DateTime\MILLISECONDS_PER_SECOND;

/**
 * Returns the number of seconds since the Unix epoch of a wall-clock date and time in a timezone.
 *
 * Unlike {@see \Psl\DateTime\DateTime::fromParts()}, this never rejects a wall-clock time:
 *
 * - A time skipped by a forward transition (e.g. the start of daylight saving time) is moved forward
 *   by the length of the transition, so 01:30 on a day that jumps from 01:00 to 02:00 becomes 02:30.
 * - A time that happens twice (e.g. the end of daylight saving time) resolves to the occurrence with
 *   the UTC offset in effect at `$referenceSeconds` when there is one, and to the later occurrence otherwise.
 *
 * @param int<1, 12> $month
 * @param int<1, 31> $day
 * @param int<0, 23> $hours
 * @param int<0, 59> $minutes
 * @param int<0, 59> $seconds
 * @param int $referenceSeconds An instant, in seconds since the Unix epoch, whose UTC offset is preferred.
 *
 * @throws Exception\OverflowException If the year is greater than 5368709, the latest supported year.
 * @throws Exception\UnderflowException If the year is less than -5838268, the earliest supported year.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function resolve_local_date_time(
    Timezone $timezone,
    int $year,
    int $month,
    int $day,
    int $hours,
    int $minutes,
    int $seconds,
    int $referenceSeconds,
): int {
    $calendar = namespace\create_intl_calendar_from_date_time(
        $timezone,
        $year,
        $month,
        $day,
        $hours,
        $minutes,
        $seconds,
    );

    // ICU moves skipped times forward and resolves repeated times to their later occurrence.
    $milliseconds = $calendar->getTime();
    $offset = $calendar->get(IntlCalendar::FIELD_ZONE_OFFSET) + $calendar->get(IntlCalendar::FIELD_DST_OFFSET);

    // The same calendar gives the preferred offset, so a single calendar is created.
    $calendar->setTime($referenceSeconds * MILLISECONDS_PER_SECOND);
    $preferredOffset = $calendar->get(IntlCalendar::FIELD_ZONE_OFFSET) + $calendar->get(IntlCalendar::FIELD_DST_OFFSET);

    if ($offset !== $preferredOffset) {
        // The same wall-clock time under the preferred offset; it is valid only if the timezone
        // actually uses that offset at that instant.
        $alternative = $milliseconds + $offset - $preferredOffset;
        $calendar->setTime($alternative);
        $alternativeOffset =
            $calendar->get(IntlCalendar::FIELD_ZONE_OFFSET) + $calendar->get(IntlCalendar::FIELD_DST_OFFSET);

        if ($alternativeOffset === $preferredOffset) {
            $milliseconds = $alternative;
        }
    }

    return (int) ($milliseconds / MILLISECONDS_PER_SECOND);
}
