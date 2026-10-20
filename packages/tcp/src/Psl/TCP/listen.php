<?php

declare(strict_types=1);

namespace Psl\TCP;

use Psl\Network;

use function str_contains;
use function str_starts_with;

use const PHP_OS_FAMILY;

/**
 * Create a TCP listener bound to the given address.
 *
 * @param non-empty-string $host
 * @param int<0, max> $port
 *
 * @throws Network\Exception\RuntimeException If failed to listen on given address.
 *
 * @api
 */
function listen(
    string $host = '127.0.0.1',
    int $port = 0,
    ListenConfiguration $configuration = new ListenConfiguration(),
): ListenerInterface {
    $socketContext = [
        'socket' => [
            'ipv6_v6only' => true,
            'so_reuseaddr' => PHP_OS_FAMILY === 'Windows' ? $configuration->reusePort : $configuration->reuseAddress,
            'so_reuseport' => $configuration->reusePort,
            'so_broadcast' => false,
            'tcp_nodelay' => $configuration->noDelay,
            'backlog' => $configuration->backlog,
        ],
    ];

    if (null !== $configuration->bindTo) {
        $socketContext['socket']['bindto'] = $configuration->bindTo;
    }

    if (str_contains($host, ':') && !str_starts_with($host, '[')) {
        $host = "[{$host}]";
    }

    $socket = Network\Internal\server_listen("tcp://{$host}:{$port}", $socketContext);

    return new Internal\Listener($socket, $configuration->idleConnections);
}
