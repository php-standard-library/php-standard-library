<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use Psl\DateTime\Exception;

use function hrtime;

use const Psl\DateTime\NANOSECONDS_PER_SECOND;

/**
 * @throws Exception\RuntimeException If the system does not provide a monotonic timer.
 *
 * @return array{int, int}
 *
 * @internal
 *
 * @mago-expect lint:no-shorthand-ternary
 */
function high_resolution_time(): array
{
    /**
     * @var null|list{int, int} $offset
     */
    static $offset = null;

    if (null === $offset) {
        $offset = hrtime() ?: null;
        // @codeCoverageIgnoreStart
        if (null === $offset) {
            throw new Exception\RuntimeException('The system does not provide a monotonic timer.');
        }

        // @codeCoverageIgnoreEnd

        $time = namespace\system_time();

        $offset = [
            $time[0] - $offset[0],
            $time[1] - $offset[1],
        ];
    }

    [$seconds_offset, $nanoseconds_offset] = $offset;
    $highResolutionTime = hrtime();
    // @codeCoverageIgnoreStart
    if (false === $highResolutionTime) {
        throw new Exception\RuntimeException('The system does not provide a monotonic timer.');
    }

    [$seconds, $nanoseconds] = $highResolutionTime;

    $nanosecondsAdjusted = $nanoseconds + $nanoseconds_offset;
    if ($nanosecondsAdjusted >= NANOSECONDS_PER_SECOND) {
        ++$seconds;
        $nanosecondsAdjusted -= NANOSECONDS_PER_SECOND;
    } elseif ($nanosecondsAdjusted < 0) {
        --$seconds;
        $nanosecondsAdjusted += NANOSECONDS_PER_SECOND;
    }

    // @codeCoverageIgnoreEnd

    $seconds += $seconds_offset;
    $nanoseconds = $nanosecondsAdjusted;

    return [$seconds, $nanoseconds];
}
