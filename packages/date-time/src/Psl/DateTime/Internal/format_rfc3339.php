<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use Psl\DateTime\SecondsStyle;
use Psl\DateTime\Timestamp;
use Psl\DateTime\Timezone;

use function str_pad;
use function str_replace;
use function substr;

use const STR_PAD_LEFT;

/**
 * @internal
 *
 * @psalm-mutation-free
 */
function format_rfc3339(
    Timestamp $timestamp,
    null|SecondsStyle $secondsStyle = null,
    bool $useZ = false,
    null|Timezone $timezone = null,
): string {
    $secondsStyle ??= SecondsStyle::fromTimestamp($timestamp);

    if (null === $timezone) {
        $timezone = Timezone::UTC;
    } elseif ($useZ) {
        $useZ = Timezone::UTC === $timezone;
    }

    $seconds = $timestamp->getSeconds();
    $nanoseconds = $timestamp->getNanoseconds();

    // Intl formatter cannot handle nanoseconds and microseconds, do it manually instead.
    $fraction = substr(str_pad((string) $nanoseconds, 9, '0', STR_PAD_LEFT), 0, $secondsStyle->value);
    if ('' !== $fraction) {
        $fraction = '.' . $fraction;
    }

    // `uuuu` is the astronomical (extended) year; `yyyy` would print the era-relative year,
    // turning 1 BC (year 0) into "0001".
    $pattern = match ($useZ) {
        true => 'uuuu-MM-dd\'T\'HH:mm:ss@ZZZZZ',
        false => 'uuuu-MM-dd\'T\'HH:mm:ss@xxx',
    };

    $formatter = namespace\create_intl_date_formatter(pattern: $pattern, timezone: $timezone);
    $rfcString = $formatter->format($seconds);

    /** @var string */
    return str_replace('@', $fraction, $rfcString);
}
