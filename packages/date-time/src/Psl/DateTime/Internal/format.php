<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use Psl\DateTime\FormatPattern;
use Psl\DateTime\Timestamp;
use Psl\DateTime\Timezone;
use Psl\Locale\Locale;

use const Psl\DateTime\NANOSECONDS_PER_SECOND;

/**
 * Formats a timestamp using an ICU pattern, or one of the {@see FormatPattern} cases.
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
): string {
    // ICU has no "seconds since the epoch" field.
    if (FormatPattern::UnixTimestamp === $pattern) {
        return (string) $timestamp->getSeconds();
    }

    $formatter = namespace\create_intl_date_formatter(null, null, $pattern, $timezone, $locale);

    /** @var string */
    return $formatter->format($timestamp->getSeconds() + ($timestamp->getNanoseconds() / NANOSECONDS_PER_SECOND));
}
