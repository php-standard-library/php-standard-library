<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Psl\Network;

use function str_starts_with;
use function stream_socket_get_name;

use const PHP_OS_FAMILY;

/**
 * Read the local or the peer address of a Unix domain socket.
 *
 * Unlike {@see Network\Internal\get_sock_name()} and {@see Network\Internal\get_peer_name()}, which
 * read a name containing ":" as a TCP host and port, this keeps the whole name as a Unix socket path.
 * A name in the Linux abstract namespace keeps its leading NUL byte.
 *
 * @param resource $socket
 * @param bool $peer Whether to read the peer address instead of the local address.
 *
 * @return null|Network\Address The address, or null if the socket is unnamed, as a client that did not
 *                              bind is: PHP then reports no name or an empty one. Outside Linux, which
 *                              alone has the abstract namespace, a name starting with a NUL byte is
 *                              unnamed too; PHP builds it from bytes that the system did not write.
 *
 * @internal
 */
function get_address(mixed $socket, bool $peer): null|Network\Address
{
    $name = stream_socket_get_name($socket, $peer);
    if (false === $name || '' === $name) {
        return null;
    }

    if (PHP_OS_FAMILY !== 'Linux' && str_starts_with($name, "\0")) {
        return null;
    }

    return Network\Address::unix($name);
}
