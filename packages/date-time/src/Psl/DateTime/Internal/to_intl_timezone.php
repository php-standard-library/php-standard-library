<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlTimeZone;
use Psl\DateTime\Exception;
use Psl\DateTime\Timezone;

use function sprintf;

use const INTL_ICU_VERSION;

/**
 * @throws Exception\RuntimeException If the ICU data of this build does not know the zone.
 *
 * @pure
 *
 * @internal
 */
function to_intl_timezone(Timezone $timezone): IntlTimeZone
{
    $intlTimezone = namespace\create_intl_timezone($timezone);
    if (null === $intlTimezone) {
        throw new Exception\RuntimeException(sprintf(
            'The ICU data of this build (ICU %s) does not know the timezone "%s".',
            INTL_ICU_VERSION,
            $timezone->value,
        ));
    }

    return $intlTimezone;
}
