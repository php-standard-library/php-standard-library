<?php

declare(strict_types=1);

namespace Psl\Unix;

use Psl\Network;

/**
 * Create a Unix domain socket listener bound to the given path.
 *
 * A path starting with a NUL byte names a socket in the Linux abstract namespace.
 *
 * @param non-empty-string $path
 *
 * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one, or is
 *                                                    longer than the system can hold.
 * @throws Network\Exception\RuntimeException If failed to listen on given path, or if on Windows.
 *
 * @api
 */
function listen(string $path, ListenConfiguration $configuration = new ListenConfiguration()): ListenerInterface
{
    Internal\assert_not_windows();

    Internal\assert_valid_path($path);

    $context = ['socket' => [
        'backlog' => $configuration->backlog,
    ]];

    $socket = Network\Internal\server_listen("unix://{$path}", $context);

    return new Internal\Listener($socket, Network\Address::unix($path), $configuration->idleConnections);
}
