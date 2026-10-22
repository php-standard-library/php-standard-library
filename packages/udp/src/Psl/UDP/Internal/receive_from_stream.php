<?php

declare(strict_types=1);

namespace Psl\UDP\Internal;

use Psl\Network;

use function stream_select;
use function stream_socket_recvfrom;

use const STREAM_PEEK;

/**
 * Receive a datagram and its sender's address from a UDP stream that the event loop reported readable, or peek at it.
 *
 * `stream_socket_recvfrom()` returns false alike when no datagram is waiting and when the receive failed. A socket that
 * is no longer readable after such a result had no datagram after all, as when another receive took it first, and the
 * caller waits again. One that stays readable has an error pending, which a peek leaves in place on BSD systems, such
 * as macOS, after an ICMP port-unreachable message: waiting again would wake at once, for as long as the error stays.
 * The receive then runs once more, in case a datagram arrived in between, and fails if it finds nothing again. A
 * receive that consumes the error finds the socket no longer readable, and waits again.
 *
 * PHP keeps the prefix of a datagram longer than $maxBytes on Windows, as POSIX systems do, only when it is asked for
 * the sender's address; without it, the receive fails with WSAEMSGSIZE. So the address is always asked for.
 *
 * @param resource $stream
 * @param positive-int $maxBytes
 * @param bool $peek Whether to leave the datagram queued.
 *
 * @return null|array{string, string} The datagram and its sender's address, or null if the socket had no datagram
 *                                    after all.
 *
 * @throws Network\Exception\RuntimeException If the socket stays readable while the receive finds nothing.
 *
 * @internal
 */
function receive_from_stream(mixed $stream, int $maxBytes, bool $peek): null|array
{
    $flags = $peek ? STREAM_PEEK : 0;
    $address = '';
    $data = @stream_socket_recvfrom($stream, $maxBytes, $flags, $address);
    if (false !== $data) {
        return [$data, $address];
    }

    $read = [$stream];
    $write = null;
    $except = null;
    // A select that fails, as for a descriptor above FD_SETSIZE, counts as readable, so that the call cannot spin.
    if (0 === @stream_select($read, $write, $except, seconds: 0, microseconds: 0)) {
        return null;
    }

    $address = '';
    $data = @stream_socket_recvfrom($stream, $maxBytes, $flags, $address);
    if (false !== $data) {
        return [$data, $address];
    }

    throw new Network\Exception\RuntimeException('Failed to ' . ($peek ? 'peek' : 'receive') . ' UDP datagram.');
}
