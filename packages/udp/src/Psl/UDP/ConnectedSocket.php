<?php

declare(strict_types=1);

namespace Psl\UDP;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;
use Socket as NativeSocket;

use function fclose;
use function in_array;
use function is_resource;
use function socket_import_stream;
use function socket_last_error;
use function socket_recv;
use function socket_strerror;
use function stream_set_blocking;
use function stream_socket_get_name;
use function stream_socket_sendto;

use const MSG_PEEK;
use const PHP_OS_FAMILY;
use const SOCKET_EAGAIN;
use const SOCKET_EINTR;
use const SOCKET_EWOULDBLOCK;

/**
 * A connected UDP socket for communicating with a single peer.
 *
 * Obtained via {@see Socket::connect()} or {@see connect()}.
 *
 * @api
 */
final class ConnectedSocket implements Network\SocketInterface, IO\StreamHandleInterface
{
    /**
     * @var resource|closed-resource|null
     */
    private mixed $stream;

    private readonly Network\Address $localAddress;

    /**
     * The socket imported into ext-sockets, through which {@see receive()} and {@see peek()} read, or null.
     *
     * Only ext-sockets reports why a receive failed: `stream_socket_recvfrom()` returns false alike when no datagram
     * is waiting and when the receive failed, as after an ICMP port-unreachable message from the peer. The socket is
     * imported once, on POSIX systems where ext-sockets is available; it is null on Windows, where the stream keeps
     * PHP's emulation of truncated datagrams, without ext-sockets, and once the socket is closed.
     */
    private null|NativeSocket $socket;

    /**
     * The receives, peeks and sends waiting on the socket, which closing it ends.
     */
    private readonly Internal\PendingWaits $waits;

    /**
     * Wrap a connected UDP socket, taking ownership of it.
     *
     * @internal Use {@see Socket::connect()} or {@see connect()} to obtain a ConnectedSocket.
     *
     * @param resource $stream The connected socket, which this object closes once it is closed or destroyed.
     * @param Network\Address $peerAddress The address the socket is connected to.
     */
    public function __construct(
        mixed $stream,
        private readonly Network\Address $peerAddress,
    ) {
        $this->stream = $stream;
        $this->waits = new Internal\PendingWaits();
        stream_set_blocking($stream, false);
        $this->socket = self::importSocket($stream);

        $name = @stream_socket_get_name($stream, false);
        if ($name === false) {
            // @codeCoverageIgnoreStart
            throw new Network\Exception\RuntimeException('Failed to get local address.');
            // @codeCoverageIgnoreEnd
        }

        $this->localAddress = Internal\parse_address($name);
    }

    /**
     * Send a datagram to the connected peer.
     *
     * @return int<0, max> Number of bytes sent.
     *
     * @throws Network\Exception\RuntimeException If the send fails.
     * @throws Network\Exception\InvalidArgumentException If the datagram exceeds the maximum size.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    public function send(string $data, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        Internal\validate_payload_size($data);
        $stream = $this->getResource();

        $this->waits->writable($stream, $cancellation);
        $stream = $this->getResource();

        $result = @stream_socket_sendto($stream, $data);
        if ($result === false || $result === -1) {
            // @codeCoverageIgnoreStart
            throw new Network\Exception\RuntimeException('Failed to send UDP datagram.');
            // @codeCoverageIgnoreEnd
        }

        /** @var int<0, max> */
        return $result;
    }

    /**
     * Receive a datagram from the connected peer.
     *
     * A datagram longer than $maxBytes is truncated to its first $maxBytes bytes, and the rest is discarded.
     *
     * @param positive-int $maxBytes
     *
     * @throws Network\Exception\RuntimeException If the receive fails. On POSIX systems with ext-sockets, its code is
     *                                             the errno, such as ECONNREFUSED once the peer's port was
     *                                             unreachable, and the socket stays usable.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    public function receive(
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->read($maxBytes, false, $cancellation);
    }

    /**
     * Peek at an incoming datagram without consuming it.
     *
     * A datagram longer than $maxBytes is truncated to its first $maxBytes bytes, and stays queued whole.
     *
     * @param positive-int $maxBytes
     *
     * @throws Network\Exception\RuntimeException If the peek fails. On POSIX systems with ext-sockets, its code is
     *                                             the errno, such as ECONNREFUSED once the peer's port was
     *                                             unreachable, and the socket stays usable. Without the extension,
     *                                             and on Windows, a peek that finds nothing while the socket stays
     *                                             readable fails with 0 as its code, as while an ICMP
     *                                             port-unreachable message is pending on BSD systems, such as macOS;
     *                                             a receive consumes such an error.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    public function peek(int $maxBytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        return $this->read($maxBytes, true, $cancellation);
    }

    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return $this->localAddress;
    }

    /**
     * Get the peer address this socket is connected to.
     */
    public function getPeerAddress(): Network\Address
    {
        return $this->peerAddress;
    }

    /**
     * @return resource|object|null
     */
    #[Override]
    public function getStream(): mixed
    {
        if (!is_resource($this->stream)) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        return $this->stream;
    }

    // @codeCoverageIgnoreStart
    #[Override]
    public function isClosed(): bool
    {
        return !is_resource($this->stream);
    }

    // @codeCoverageIgnoreEnd

    /**
     * Close the socket. Every call waiting on it fails with `AlreadyClosedException`. Closing a closed socket does
     * nothing.
     */
    #[Override]
    public function close(): void
    {
        $this->waits->failAll(new IO\Exception\AlreadyClosedException('UDP socket has already been closed.'));
        $this->socket = null;
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Wait for a datagram, then receive it, or peek at it without consuming it.
     *
     * A receive that finds no datagram after all, as when another receive took it first, waits again. With the
     * imported socket, one that fails throws with the errno, and leaves the socket usable. Without it,
     * `stream_socket_recvfrom()` cannot tell a failure from a missing datagram. A receive that returns nothing waits
     * again if the socket is no longer readable, as after a receive that consumed an ICMP port-unreachable message,
     * which goes unreported. It fails if the socket stays readable, as when a peek leaves that error pending on BSD
     * systems ({@see Internal\receive_from_stream()}).
     *
     * @param positive-int $maxBytes
     * @param bool $peek Whether to leave the datagram queued.
     *
     * @throws Network\Exception\RuntimeException If the receive fails: with the imported socket, with the errno as its
     *                                             code; without it, when the socket stays readable while the receive
     *                                             finds nothing.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    private function read(int $maxBytes, bool $peek, CancellationTokenInterface $cancellation): string
    {
        $stream = $this->getResource();
        $operation = $peek ? 'peek' : 'receive';

        while (true) {
            $this->waits->readable($stream, $cancellation);
            // The socket may have been closed once the wait had ended, before this fiber ran again.
            $stream = $this->getResource();

            // A socket exists only if the guard passed when it was imported; every call into ext-sockets still sits
            // behind the guard.
            $socket = $this->socket;
            if (null !== $socket && Internal\sockets_available()) {
                $data = null;
                $received = @socket_recv($socket, $data, $maxBytes, $peek ? MSG_PEEK : 0);
                if (false !== $received) {
                    // An empty datagram leaves the buffer null.
                    return 0 === $received ? '' : $data;
                }

                // No datagram after all, as when another receive took it, or an interrupted call: wait again.
                $errno = socket_last_error($socket);
                if (in_array($errno, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK, SOCKET_EINTR], true)) {
                    continue;
                }

                throw new Network\Exception\RuntimeException(
                    'Failed to ' . $operation . ' UDP datagram: ' . socket_strerror($errno) . '.',
                    $errno,
                );
            }

            // The stream path asks for the sender's address, which keeps the prefix of a long datagram on Windows, and
            // drops it.
            $received = Internal\receive_from_stream($stream, $maxBytes, $peek);
            if (null !== $received) {
                return $received[0];
            }
        }
    }

    /**
     * Import the socket into ext-sockets, so that a failed receive reports its errno.
     *
     * Returns null on Windows, where `socket_recv()` would lose PHP's emulation of truncated datagrams, when
     * ext-sockets is unavailable, and when the stream cannot be imported; receives then use the stream.
     *
     * @param resource $stream
     */
    private static function importSocket(mixed $stream): null|NativeSocket
    {
        if (PHP_OS_FAMILY === 'Windows' || !Internal\sockets_available()) {
            return null;
        }

        $socket = @socket_import_stream($stream);

        return false === $socket ? null : $socket;
    }

    /**
     * @return resource
     *
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
     */
    private function getResource(): mixed
    {
        if (!is_resource($this->stream)) {
            throw new IO\Exception\AlreadyClosedException('UDP socket has already been closed.');
        }

        return $this->stream;
    }
}
