<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use IntlTimeZone;
use Psl;
use Psl\DateTime\Timezone;

use const INTL_ICU_VERSION;

/**
 * @pure
 *
 * @internal
 */
function to_intl_timezone(Timezone $timezone): IntlTimeZone
{
    $tz = namespace\create_intl_timezone($timezone);

    Psl\invariant(
        null !== $tz,
        'The ICU data of this build (ICU %s) does not know the timezone "%s".',
        INTL_ICU_VERSION,
        $timezone->value,
    );

    return $tz;
}
