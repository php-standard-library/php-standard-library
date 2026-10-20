<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Psl\Network;

use function stream_socket_get_name;

/**
 * Read the local or the peer address of a Unix domain socket.
 *
 * Unlike {@see Network\Internal\get_sock_name()} and {@see Network\Internal\get_peer_name()}, which
 * read a name containing ":" as a TCP host and port, this keeps the whole name as a Unix socket path.
 *
 * @param resource $socket
 * @param bool $peer Whether to read the peer address instead of the local address.
 *
 * @return null|Network\Address The address, or null if PHP cannot read a name for the socket: an
 *                              unbound socket has none, and PHP does not report a name in the
 *                              Linux abstract namespace, as it starts with a NUL byte.
 *
 * @internal
 */
function get_address(mixed $socket, bool $peer): null|Network\Address
{
    $name = stream_socket_get_name($socket, $peer);
    if (false === $name || '' === $name) {
        return null;
    }

    return Network\Address::unix($name);
}
