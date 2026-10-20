<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use Psl\DateTime\Exception;

use function intdiv;

use const PHP_INT_MAX;
use const PHP_INT_MIN;
use const Psl\DateTime\MINUTES_PER_HOUR;
use const Psl\DateTime\NANOSECONDS_PER_SECOND;
use const Psl\DateTime\SECONDS_PER_HOUR;
use const Psl\DateTime\SECONDS_PER_MINUTE;

/**
 * Normalizes duration parts so that all non-zero parts have the same sign, minutes and seconds are
 * within -59..59, and nanoseconds are within -999999999..999999999.
 *
 * The parts are added up without any intermediate value overflowing (`3600 * $hours` would, for large
 * hours), so whether it throws, and which exception, depends only on the total.
 *
 * The total number of seconds is kept within -PHP_INT_MAX..PHP_INT_MAX, so that it can always be negated.
 *
 * @throws Exception\OverflowException If the total number of seconds is greater than PHP_INT_MAX.
 * @throws Exception\UnderflowException If the total number of seconds is less than -PHP_INT_MAX.
 *
 * @return array{int, int<-59, 59>, int<-59, 59>, int<-999999999, 999999999>}
 *
 * @internal
 *
 * @pure
 */
function normalize_duration_parts(int $hours, int $minutes, int $seconds, int $nanoseconds): array
{
    // Fast path: with parts this small, the total number of seconds is far from the integer limits.
    if (
        $hours <= 1_099_511_627_776
        && $hours >= -1_099_511_627_776
        && $minutes <= 1_099_511_627_776
        && $minutes >= -1_099_511_627_776
        && $seconds <= 4_503_599_627_370_496
        && $seconds >= -4_503_599_627_370_496
    ) {
        $total =
            ($hours * SECONDS_PER_HOUR)
            + ($minutes * SECONDS_PER_MINUTE)
            + $seconds
            + intdiv($nanoseconds, NANOSECONDS_PER_SECOND);
        $ns = $nanoseconds % NANOSECONDS_PER_SECOND;
        if ($total < 0 && $ns > 0) {
            ++$total;
            $ns -= NANOSECONDS_PER_SECOND;
        } elseif ($total > 0 && $ns < 0) {
            --$total;
            $ns += NANOSECONDS_PER_SECOND;
        }

        return [
            intdiv($total, SECONDS_PER_HOUR),
            intdiv($total, SECONDS_PER_MINUTE) % MINUTES_PER_HOUR,
            $total % SECONDS_PER_MINUTE,
            $ns,
        ];
    }

    $overflow = 'The duration is too long: its total seconds exceed PHP_INT_MAX.';
    $underflow = 'The duration is too long: its total seconds are less than -PHP_INT_MAX.';

    // Split every part into whole hours and a remainder of less than an hour, so that nothing overflows
    // before the parts are added up: the remainders add up to less than three hours, and the whole hours
    // of everything but `$hours` stay below 2^58.
    $secondsFromNanoseconds = intdiv($nanoseconds, NANOSECONDS_PER_SECOND);
    $ns = $nanoseconds % NANOSECONDS_PER_SECOND;
    $rest =
        ($seconds % SECONDS_PER_HOUR)
        + (($minutes % MINUTES_PER_HOUR) * SECONDS_PER_MINUTE)
        + ($secondsFromNanoseconds % SECONDS_PER_HOUR);
    $otherHours =
        intdiv($seconds, SECONDS_PER_HOUR)
        + intdiv($minutes, MINUTES_PER_HOUR)
        + intdiv($secondsFromNanoseconds, SECONDS_PER_HOUR)
        + intdiv($rest, SECONDS_PER_HOUR);
    $rest %= SECONDS_PER_HOUR;

    // When the sum of the hours overflows, it is far beyond the supported total, with the sign of `$hours`.
    if ($otherHours > 0 && $hours > (PHP_INT_MAX - $otherHours)) {
        throw new Exception\OverflowException($overflow);
    }

    if ($otherHours < 0 && $hours < (PHP_INT_MIN - $otherHours)) {
        throw new Exception\UnderflowException($underflow);
    }

    $h = $hours + $otherHours;
    $m = intdiv($rest, SECONDS_PER_MINUTE) % MINUTES_PER_HOUR;
    $s = $rest % SECONDS_PER_MINUTE;

    // Give every part the sign of the whole duration: that is the sign of the most significant non-zero
    // part, as each smaller part is now less than one unit of the next larger one.
    $sign = $h <=> 0;
    if (0 === $sign) {
        $sign = $m <=> 0;
    }

    if (0 === $sign) {
        $sign = $s <=> 0;
    }

    if ($sign > 0) {
        if ($ns < 0) {
            $ns += NANOSECONDS_PER_SECOND;
            --$s;
        }

        if ($s < 0) {
            $s += SECONDS_PER_MINUTE;
            --$m;
        }

        if ($m < 0) {
            $m += MINUTES_PER_HOUR;
            --$h;
        }
    } elseif ($sign < 0) {
        if ($ns > 0) {
            $ns -= NANOSECONDS_PER_SECOND;
            ++$s;
        }

        if ($s > 0) {
            $s -= SECONDS_PER_MINUTE;
            ++$m;
        }

        if ($m > 0) {
            $m -= MINUTES_PER_HOUR;
            ++$h;
        }
    }

    // Keep the total number of seconds within -PHP_INT_MAX..PHP_INT_MAX.
    $rest = ($m * SECONDS_PER_MINUTE) + $s;
    if ($h > 0 && $h > intdiv(PHP_INT_MAX - $rest, SECONDS_PER_HOUR)) {
        throw new Exception\OverflowException($overflow);
    }

    if ($h < 0 && $h < intdiv(-PHP_INT_MAX - $rest, SECONDS_PER_HOUR)) {
        throw new Exception\UnderflowException($underflow);
    }

    return [$h, $m, $s, $ns];
}
