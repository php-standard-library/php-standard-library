<?php

declare(strict_types=1);

namespace Psl\UDP;

use Psl\Network;

use function stream_socket_client;

/**
 * Create a connected UDP socket to the given host and port.
 *
 * The target address determines the local socket family.
 *
 * @param non-empty-string $host
 * @param int<0, 65535> $port
 *
 * @throws Network\Exception\RuntimeException If binding or connecting fails.
 *
 * @api
 */
function connect(string $host, int $port): ConnectedSocket
{
    $address = Network\Address::udp($host, $port);
    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client($address->toString(), $errno, $errstr);
    if ($stream === false) {
        throw new Network\Exception\RuntimeException(
            "Failed to connect UDP socket to {$address->toString()}: {$errstr}",
            (int) $errno,
        );
    }

    return new ConnectedSocket($stream, $address);
}
