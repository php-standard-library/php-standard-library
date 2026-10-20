<?php

declare(strict_types=1);

namespace Psl\DateTime\Internal;

use Psl\DateTime\Exception;
use Psl\DateTime\Timezone;

use function date_default_timezone_get;
use function sprintf;

/**
 * @throws Exception\RuntimeException If the default timezone has no {@see Timezone} equivalent.
 *
 * @internal
 *
 * @psalm-mutation-free
 */
function default_timezone(): Timezone
{
    /**
     * `date_default_timezone_get()` may return a backward-compatible link (e.g. "US/Eastern"), or one of the
     * "Others" timezones mentioned in the PHP documentation (https://www.php.net/manual/en/timezones.others.php);
     * resolve those to the zone, or the fixed offset, they stand for.
     */
    $timezoneId = date_default_timezone_get();

    return (
        namespace\resolve_timezone($timezoneId) ?? throw new Exception\RuntimeException(sprintf(
            'The default timezone "%s" is not supported.',
            $timezoneId,
        ))
    );
}
