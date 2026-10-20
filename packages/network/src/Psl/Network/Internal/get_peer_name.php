<?php

declare(strict_types=1);

namespace Psl\Network\Internal;

use Psl\Network;

use function error_clear_last;
use function error_get_last;
use function stream_socket_get_name;
use function strrpos;
use function substr;

/**
 * @param resource $socket
 * @param null|non-empty-string $peerName
 *
 * @throws Network\Exception\RuntimeException If unable to retrieve peer address.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function get_peer_name(mixed $socket, null|string $peerName = null): Network\Address
{
    error_clear_last();
    /** @var non-empty-string|false $result */
    $result = $peerName ?? stream_socket_get_name($socket, true);

    if (false !== $result) {
        $separatorPosition = strrpos($result, ':');
        if (false === $separatorPosition) {
            return Network\Address::unix($result);
        }

        /** @var non-empty-string $host */
        $host = substr($result, 0, $separatorPosition);
        /** @var int<0, 65535> $port */
        $port = (int) substr($result, $separatorPosition + 1);

        return Network\Address::tcp($host, $port);
    }

    $error = error_get_last();
    throw new Network\Exception\RuntimeException(
        'Error retrieving peer address: ' . ($error['message'] ?? 'the socket has no peer address.'),
        $error['type'] ?? 0,
    );
}
