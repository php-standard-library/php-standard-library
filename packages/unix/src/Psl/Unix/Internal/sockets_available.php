<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use function extension_loaded;
use function function_exists;

/**
 * Whether ext-sockets is available, with every function of it that the unix package calls.
 *
 * The unix package only suggests the extension: each call into it first checks this guard, and keeps a way to work
 * without it, or fails before it touches a socket. A function that the `disable_functions` setting removes counts as
 * unavailable, since calling it would fail.
 *
 * @internal
 */
function sockets_available(): bool
{
    static $available = null;

    return $available ??=
        extension_loaded('sockets')
        && function_exists('socket_create')
        && function_exists('socket_bind')
        && function_exists('socket_set_nonblock')
        && function_exists('socket_connect')
        && function_exists('socket_export_stream')
        && function_exists('socket_close')
        && function_exists('socket_last_error')
        && function_exists('socket_strerror');
}
