<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlTimeZone;
use Psl\DateTime\Timezone;

use function str_starts_with;

/**
 * Creates the {@see IntlTimeZone} of a {@see Timezone}, or returns null if the ICU data of this build
 * does not know the zone.
 *
 * Zones renamed in tzdata are unknown to ICU data that predates the rename, which still knows them by
 * their previous name: Europe/Kyiv (tzdata 2022b, ICU 72) by Europe/Kiev, and Pacific/Kanton (tzdata
 * 2021b, ICU 71) by Pacific/Enderbury. Zones that are new in tzdata have no such fallback.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function create_intl_timezone(Timezone $timezone): null|IntlTimeZone
{
    $value = $timezone->value;
    if (str_starts_with($value, '+') || str_starts_with($value, '-')) {
        $value = 'GMT' . $value;
    }

    $intlTimezone = IntlTimeZone::createTimeZone($value);
    if (null !== $intlTimezone && 'Etc/Unknown' === $intlTimezone->getID()) {
        $previousIdentifier = match ($timezone) {
            Timezone::EuropeKyiv => 'Europe/Kiev',
            Timezone::PacificKanton => 'Pacific/Enderbury',
            default => null,
        };

        $intlTimezone = null === $previousIdentifier ? null : IntlTimeZone::createTimeZone($previousIdentifier);
    }

    if (null === $intlTimezone || 'Etc/Unknown' === $intlTimezone->getID()) {
        return null;
    }

    return $intlTimezone;
}
