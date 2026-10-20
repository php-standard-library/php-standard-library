<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlTimeZone;
use Psl\DateTime\Timezone;

use function abs;
use function intdiv;
use function method_exists;
use function sprintf;
use function str_starts_with;

/**
 * Resolves a timezone identifier to a {@see Timezone} case, or returns null if it has none, or if the
 * ICU data of this build does not know the zone (e.g. America/Coyhaique before ICU 78).
 *
 * Besides the identifiers of the enum cases, this understands:
 *
 * - aliases and backward-compatible links of a supported zone, e.g. "US/Eastern" (America/New_York),
 *   "Asia/Calcutta" (Asia/Kolkata), or "Etc/UTC" (UTC), using ICU's equivalent identifiers;
 * - fixed offsets, e.g. "+00:00" (UTC), "Etc/GMT+5" (-05:00), or "EST" (-05:00), when an offset
 *   case exists for them.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function resolve_timezone(string $identifier): null|Timezone
{
    $timezone = Timezone::tryFrom($identifier);
    if (null !== $timezone) {
        // A zone newer than the ICU data of this build cannot be used, and ICU knows no equivalent of it.
        return null !== namespace\create_intl_timezone($timezone) ? $timezone : null;
    }

    if (str_starts_with($identifier, '+') || str_starts_with($identifier, '-')) {
        $identifier = 'GMT' . $identifier;
    }

    // The zone a link points to, e.g. "America/Panama" for "EST" (PHP 8.4+ built against ICU 74+).
    if (method_exists(IntlTimeZone::class, 'getIanaID')) {
        $ianaIdentifier = IntlTimeZone::getIanaID($identifier);
        if (false !== $ianaIdentifier) {
            $timezone = Timezone::tryFrom($ianaIdentifier);
            if (null !== $timezone) {
                return $timezone;
            }
        }
    }

    $count = IntlTimeZone::countEquivalentIDs($identifier);
    if (false !== $count) {
        for ($i = 0; $i < $count; $i++) {
            $equivalent = IntlTimeZone::getEquivalentID($identifier, $i);
            if (false === $equivalent) {
                continue;
            }

            $timezone = Timezone::tryFrom($equivalent);
            if (null !== $timezone) {
                return $timezone;
            }
        }
    }

    $intlTimezone = IntlTimeZone::createTimeZone($identifier);
    if (null === $intlTimezone || 'Etc/Unknown' === $intlTimezone->getID() || $intlTimezone->useDaylightTime()) {
        return null;
    }

    $offset = $intlTimezone->getRawOffset();
    if (0 === $offset) {
        return Timezone::UTC;
    }

    if (0 !== ($offset % 60_000)) {
        return null;
    }

    $minutes = intdiv(abs($offset), 60_000);

    return Timezone::tryFrom(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60));
}
