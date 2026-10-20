<?php

declare(strict_types=1);

namespace Psl\Unix;

use Psl\Network;

/**
 * Create a Unix domain socket listener bound to the given path.
 *
 * @param non-empty-string $path
 *
 * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one.
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

    return new Internal\Listener($socket, $configuration->idleConnections);
}
