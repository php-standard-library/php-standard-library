<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlCalendar;
use Psl\DateTime\DateStyle;
use Psl\DateTime\Exception\ParserException;
use Psl\DateTime\FormatPattern;
use Psl\DateTime\TimeStyle;
use Psl\DateTime\Timezone;
use Psl\Locale\Locale;

use function filter_var;
use function intdiv;
use function preg_match;
use function sprintf;

use const FILTER_VALIDATE_INT;
use const Psl\DateTime\MILLISECONDS_PER_SECOND;
use const Psl\DateTime\NANOSECONDS_PER_MILLISECOND;

/**
 * Parses a date and time string, returning the seconds and nanoseconds since the Unix epoch.
 *
 * Fractional seconds are kept to the millisecond, the precision of ICU's parser.
 *
 * @return array{int, int<-999999999, 999999999>}
 *
 * @internal
 *
 * @psalm-mutation-free
 *
 * @throws ParserException
 */
function parse(
    string $rawString,
    null|DateStyle $dateStyle = null,
    null|TimeStyle $timeStyle = null,
    null|FormatPattern|string $pattern = null,
    null|Timezone $timezone = null,
    null|Locale $locale = null,
): array {
    // ICU has no "seconds since the epoch" field.
    if (FormatPattern::UnixTimestamp === $pattern) {
        // Leading zeros are dropped first, as `FILTER_VALIDATE_INT` rejects them (e.g. "007").
        $matches = [];
        $seconds = 1 === preg_match('/^([+-]?)0*(\d+)$/D', $rawString, $matches)
            ? filter_var($matches[1] . $matches[2], FILTER_VALIDATE_INT)
            : false;
        if (false === $seconds) {
            throw new ParserException("Unable to interpret '{$rawString}' as a Unix timestamp.");
        }

        return [$seconds, 0];
    }

    $formatter = namespace\create_intl_date_formatter($dateStyle, $timeStyle, $pattern, $timezone, $locale);

    // `IntlDateFormatter::parse()` returns whole seconds, truncated toward zero: it drops the milliseconds ICU
    // parsed, and turns 1969-12-31 23:59:59.5 into 0. Parse into the formatter's calendar instead, cleared
    // first so that fields missing from the input default to the epoch, as they do with `parse()`.
    /** @var IntlCalendar $calendar */
    $calendar = $formatter->getCalendarObject();
    $calendar->clear();
    $formatter->setCalendar($calendar);

    if (false === $formatter->parseToCalendar($rawString)) {
        // Only show pattern in the exception if it was provided.
        if (null !== $pattern) {
            $formatterPattern = $pattern instanceof FormatPattern ? $pattern->value : $pattern;

            throw new ParserException(sprintf(
                'Unable to interpret \'%s\' as a valid date/time using pattern \'%s\'.',
                $rawString,
                $formatterPattern,
            ));
        }

        throw new ParserException("Unable to interpret '{$rawString}' as a valid date/time.");
    }

    /** @var IntlCalendar $calendar */
    $calendar = $formatter->getCalendarObject();
    $milliseconds = (int) $calendar->getTime();

    return [
        intdiv($milliseconds, MILLISECONDS_PER_SECOND),
        ($milliseconds % MILLISECONDS_PER_SECOND) * NANOSECONDS_PER_MILLISECOND,
    ];
}
