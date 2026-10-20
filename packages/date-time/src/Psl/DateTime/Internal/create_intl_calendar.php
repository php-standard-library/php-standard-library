<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use IntlGregorianCalendar;
use Psl\DateTime\Timezone;

use function locale_get_default;

/**
 * Creates a Gregorian {@see IntlCalendar} in the given timezone, set to the current time.
 *
 * `IntlCalendar::createInstance()` would pick the calendar system of the default ICU locale, e.g. the
 * Buddhist calendar for "th_TH" or the Persian calendar for "fa_IR"; an {@see IntlGregorianCalendar} is
 * always Gregorian, while it keeps the week data (first day of the week, minimal days in the first week)
 * of the default locale, as `createInstance()` did.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function create_intl_calendar(Timezone $timezone): IntlCalendar
{
    // The analyzer only knows the deprecated (year, month, day, hour, minute, second) signature of this constructor.
    // @mago-expect analysis:invalid-argument,invalid-argument,too-few-arguments
    return new IntlGregorianCalendar(namespace\to_intl_timezone($timezone), locale_get_default());
}
