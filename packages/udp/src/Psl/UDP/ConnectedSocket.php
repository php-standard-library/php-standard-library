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
use function stream_socket_recvfrom;
use function stream_socket_sendto;

use const MSG_PEEK;
use const PHP_OS_FAMILY;
use const SOCKET_EAGAIN;
use const SOCKET_EINTR;
use const SOCKET_EWOULDBLOCK;
use const STREAM_PEEK;

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
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
     */
    public function send(string $data, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        Internal\validate_payload_size($data);
        $stream = $this->getResource();

        Internal\wait_writable($stream, $cancellation);

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
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
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
     *                                             unreachable, and the socket stays usable.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
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
     * Close the socket. Closing a closed socket does nothing.
     */
    #[Override]
    public function close(): void
    {
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
     * With the imported socket, a receive that finds no datagram after all, as when the socket was readable only
     * for a moment, waits again; one that fails throws with the errno, and leaves the socket usable. Without it,
     * `stream_socket_recvfrom()` cannot tell those two apart, and a receive that returns nothing fails.
     *
     * @param positive-int $maxBytes
     * @param bool $peek Whether to leave the datagram queued.
     *
     * @throws Network\Exception\RuntimeException If the receive fails.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
     */
    private function read(int $maxBytes, bool $peek, CancellationTokenInterface $cancellation): string
    {
        $stream = $this->getResource();
        $operation = $peek ? 'peek' : 'receive';

        while (true) {
            Internal\await_readable($stream, $cancellation);

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

            // PHP keeps the prefix of a datagram longer than $maxBytes on Windows, as POSIX systems do, only when it is
            // asked for the sender's address; without it, the receive fails with WSAEMSGSIZE.
            $address = '';
            $data = @stream_socket_recvfrom($stream, $maxBytes, $peek ? STREAM_PEEK : 0, $address);
            if ($data === false) {
                // @codeCoverageIgnoreStart
                throw new Network\Exception\RuntimeException('Failed to ' . $operation . ' UDP datagram.');
                // @codeCoverageIgnoreEnd
            }

            return $data;
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
