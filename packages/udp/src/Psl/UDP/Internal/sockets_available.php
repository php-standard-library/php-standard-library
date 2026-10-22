<?php

declare(strict_types=1);

namespace Psl\UDP\Internal;

use function extension_loaded;
use function function_exists;

/**
 * Whether ext-sockets is available, with every function of it that the udp package calls.
 *
 * The udp package only suggests the extension: each call into it first checks this guard, and keeps a way to work
 * without it. A function that the `disable_functions` setting removes counts as unavailable, since calling it would
 * fail.
 *
 * @internal
 */
function sockets_available(): bool
{
    static $available = null;

    return $available ??=
        extension_loaded('sockets')
        && function_exists('socket_import_stream')
        && function_exists('socket_recv')
        && function_exists('socket_last_error')
        && function_exists('socket_strerror');
}
