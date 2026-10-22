<?php

declare(strict_types=1);

namespace Psl\UDP;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;

use function fclose;
use function is_resource;
use function socket_addrinfo_explain;
use function socket_addrinfo_lookup;
use function socket_connect;
use function socket_import_stream;
use function str_contains;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_sendto;
use function stream_socket_server;

use const AF_INET6;
use const SOCK_DGRAM;
use const STREAM_CLIENT_CONNECT;
use const STREAM_SERVER_BIND;

/**
 * An unconnected UDP socket for sending and receiving datagrams.
 *
 * Use {@see sendTo()} and {@see receiveFrom()} to communicate with arbitrary addresses.
 *
 * To switch to connected mode, call {@see connect()} which returns a {@see ConnectedSocket}.
 *
 * @api
 */
final class Socket implements Network\SocketInterface, IO\StreamHandleInterface
{
    /**
     * The unspecified IPv6 address, as the system reports the local address of a socket bound to it.
     */
    private const string IPV6_UNSPECIFIED = '::';

    /**
     * The prefix that makes an IPv4 address in dotted form an IPv4-mapped IPv6 address.
     */
    private const string IPV4_MAPPED_PREFIX = '::ffff:';

    /**
     * @var resource|closed-resource|null
     */
    private mixed $stream;

    private readonly Network\Address $localAddress;

    /**
     * The receives, peeks and sends waiting on the socket, which closing it ends.
     */
    private readonly Internal\PendingWaits $waits;

    /**
     * Wrap a bound UDP socket, taking ownership of it.
     *
     * @param resource $stream The bound socket, which this object closes once it is closed or destroyed.
     */
    private function __construct(mixed $stream)
    {
        $this->stream = $stream;
        $this->waits = new Internal\PendingWaits();
        stream_set_blocking($stream, false);

        $name = @stream_socket_get_name($stream, false);
        if ($name === false) {
            // @codeCoverageIgnoreStart
            throw new Network\Exception\RuntimeException('Failed to get local address.');
            // @codeCoverageIgnoreEnd
        }

        $this->localAddress = Internal\parse_address($name);
    }

    /**
     * Create a UDP socket bound to the given address.
     *
     * @param non-empty-string $host
     * @param int<0, 65535> $port
     *
     * @throws Network\Exception\RuntimeException If failed to create or bind the socket.
     */
    public static function bind(
        string $host = '0.0.0.0',
        int $port = 0,
        BindConfiguration $configuration = new BindConfiguration(),
    ): self {
        $context = [
            'socket' => [
                'so_reuseaddr' => $configuration->reuseAddress,
                'so_reuseport' => $configuration->reusePort,
                'so_broadcast' => $configuration->broadcast,
            ],
        ];

        $ctx = stream_context_create($context);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server(
            Network\Address::udp($host, $port)->toString(),
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
            $ctx,
        );

        if ($socket === false) {
            throw new Network\Exception\RuntimeException("Failed to bind UDP socket: {$errstr}", (int) $errno);
        }

        return new self($socket);
    }

    /**
     * Connect to a remote address, returning a {@see ConnectedSocket}.
     *
     * This socket is closed after connecting, whether the connect succeeds or not, and the calls waiting on it fail
     * with `AlreadyClosedException`. Use the returned {@see ConnectedSocket} for further communication.
     *
     * With ext-sockets, the returned socket wraps this same OS socket, connected in place: it keeps the descriptor,
     * its options, its local address and port, and the datagrams already queued on it. It connects to the address
     * that the replacement below would take: an IP literal, or the first address of a host name, in the system's
     * order, of a family the socket reaches (a socket bound to an IPv4 address skips IPv6 addresses). An IPv6 socket
     * bound to `::` reaches an IPv4 address through its IPv4-mapped form (`::ffff:` and the IPv4 address), which its
     * local address then holds.
     *
     * Otherwise, the OS socket is closed and replaced: a new socket bound to the same local address connects
     * instead, starting without the options and the queued datagrams, and another socket may take the port in
     * between. When this socket is IPv6, an IPv4 address gets a new IPv4 socket on a new local port instead. The
     * replacement runs without the extension, for an IPv4 address on an IPv6 socket bound to any address but `::`,
     * and whenever the connect in place fails, so a connect fails, or succeeds, exactly as it does without the
     * extension.
     *
     * @param non-empty-string $host
     * @param int<0, 65535> $port
     *
     * @throws Network\Exception\RuntimeException If the connect fails.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
     */
    public function connect(string $host, int $port): ConnectedSocket
    {
        $oldStream = $this->getResource();
        $address = Network\Address::udp($host, $port);

        $connected = $this->connectInPlace($oldStream, $address);
        if (null !== $connected) {
            return $connected;
        }

        $localName = @stream_socket_get_name($oldStream, false);
        $bindto = $localName !== false ? $localName : '0.0.0.0:0';

        $this->waits->failAll(new IO\Exception\AlreadyClosedException('UDP socket has already been closed.'));
        fclose($oldStream);
        $this->stream = null;

        $context = stream_context_create(['socket' => [
            'bindto' => $bindto,
        ]]);

        $errno = 0;
        $errstr = '';
        $newStream = @stream_socket_client(
            $address->toString(),
            $errno,
            $errstr,
            null,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($newStream === false) {
            // @codeCoverageIgnoreStart
            throw new Network\Exception\RuntimeException(
                "Failed to connect UDP socket to {$address->toString()}: {$errstr}",
            );
            // @codeCoverageIgnoreEnd
        }

        return new ConnectedSocket($newStream, $address);
    }

    /**
     * Connect the OS socket in place, with ext-sockets, to the address that the replacement in {@see connect()} would
     * take.
     *
     * The replacement looks the host up once, then tries its addresses in order until one connects: it skips IPv6
     * addresses for an IPv4 socket, and gives an IPv4 address a new IPv4 socket for an IPv6 one. This method tries the
     * first of those addresses alone, so that when it gives up, the replacement fails or connects as it always did.
     *
     * @param resource $stream The socket's stream, which the connected socket takes over.
     *
     * @return null|ConnectedSocket The connected socket, or null when the replacement must connect instead.
     */
    private function connectInPlace(mixed $stream, Network\Address $address): null|ConnectedSocket
    {
        if (!Internal\sockets_available()) {
            return null;
        }

        $socket = @socket_import_stream($stream);
        if (false === $socket) {
            return null;
        }

        if (str_contains($address->host, ':')) {
            // Only an IPv6 literal holds a colon. ext-sockets reads it, with its scope, without a lookup.
            $candidates = [$address->host];
        } else {
            // An IPv4 literal or a host name: one lookup of both families, in the system's order, as the replacement
            // makes. If it finds nothing, the replacement looks the host up again, and fails as it always did.
            $candidates = [];
            $results = @socket_addrinfo_lookup($address->host, null, ['ai_socktype' => SOCK_DGRAM]);
            foreach (false === $results ? [] : $results as $result) {
                $explained = socket_addrinfo_explain($result);
                $candidates[] = AF_INET6 === $explained['ai_family']
                    ? $explained['ai_addr']['sin6_addr']
                    : $explained['ai_addr']['sin_addr'];
            }
        }

        $localHost = $this->localAddress->host;
        $ipv6Socket = str_contains($localHost, ':');
        foreach ($candidates as $candidate) {
            $ipv6Candidate = str_contains($candidate, ':');
            if ($ipv6Candidate && !$ipv6Socket) {
                // The replacement skips an IPv6 address for an IPv4 socket, too.
                continue;
            }

            $target = $candidate;
            if (!$ipv6Candidate && $ipv6Socket) {
                // An IPv6 socket bound to `::` reaches an IPv4 address through its mapped form, from the source
                // address the system picks, as the replacement's new IPv4 socket does. Any other IPv6 socket takes the
                // replacement, as it always did. One bound to an IPv6 address never reaches an IPv4 address: Linux
                // makes it IPv6-only when it binds, and macOS refuses the connect. One bound to an IPv4-mapped address
                // would keep that address, which the replacement drops: from a loopback one, macOS would connect to
                // any address, then fail every send.
                if (self::IPV6_UNSPECIFIED !== $localHost) {
                    return null;
                }

                // Given the mapped literal, ext-sockets does not look the address up, a lookup that fails on a host
                // whose only IPv6 address is the loopback one (AI_ADDRCONFIG).
                $target = self::IPV4_MAPPED_PREFIX . $candidate;
            }

            // The calls waiting on this wrapper end, as it stops owning the socket either way.
            $this->waits->failAll(new IO\Exception\AlreadyClosedException('UDP socket has already been closed.'));
            if (!@socket_connect($socket, $target, $address->port)) {
                return null;
            }

            // The connected socket takes the stream over; this wrapper lets go of it without closing it.
            $this->stream = null;

            return new ConnectedSocket($stream, $address);
        }

        return null;
    }

    /**
     * Send a datagram to a specific address.
     *
     * @return int<0, max> Number of bytes sent.
     *
     * @throws Network\Exception\RuntimeException If the send fails.
     * @throws Network\Exception\InvalidArgumentException If the datagram exceeds the maximum size.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    public function sendTo(
        string $data,
        Network\Address $address,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): int {
        Internal\validate_payload_size($data);
        $stream = $this->getResource();

        $host = str_contains($address->host, ':') ? "[{$address->host}]" : $address->host;
        $target = "{$host}:{$address->port}";

        $this->waits->writable($stream, $cancellation);
        $stream = $this->getResource();

        $result = @stream_socket_sendto($stream, $data, 0, $target);
        if ($result === false || $result === -1) {
            throw new Network\Exception\RuntimeException('Failed to send UDP datagram.');
        }

        /** @var int<0, max> */
        return $result;
    }

    /**
     * Receive a datagram and the sender's address.
     *
     * The call waits until a datagram arrives. A receive that finds no datagram after the socket became readable, as
     * when another receive took it first, waits again.
     *
     * @param positive-int $maxBytes
     *
     * @return array{string, Network\Address} [data, sender_address]
     *
     * @throws Network\Exception\RuntimeException If the socket stays readable while the receive finds nothing twice
     *                                             in a row, as when an error is pending on it.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    public function receiveFrom(
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): array {
        return $this->read($maxBytes, false, $cancellation);
    }

    /**
     * Peek at an incoming datagram and get the sender's address, without consuming it.
     *
     * The call waits until a datagram arrives. A peek that finds no datagram after the socket became readable, as
     * when a receive took it first, waits again.
     *
     * @param positive-int $maxBytes
     *
     * @return array{string, Network\Address} [data, sender_address]
     *
     * @throws Network\Exception\RuntimeException If the socket stays readable while the peek finds nothing twice in
     *                                             a row, as when an error is pending on it: a peek leaves the error
     *                                             in place on BSD systems, such as macOS.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    public function peekFrom(
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): array {
        return $this->read($maxBytes, true, $cancellation);
    }

    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return $this->localAddress;
    }

    /**
     * @return resource|object|null
     */
    #[Override]
    public function getStream(): mixed
    {
        if (!is_resource($this->stream)) {
            return null;
        }

        return $this->stream;
    }

    #[Override]
    public function isClosed(): bool
    {
        return !is_resource($this->stream);
    }

    /**
     * Close the socket. Every call waiting on it fails with `AlreadyClosedException`. Closing a closed socket does
     * nothing.
     */
    #[Override]
    public function close(): void
    {
        $this->waits->failAll(new IO\Exception\AlreadyClosedException('UDP socket has already been closed.'));
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
     * Wait for a datagram, then receive it with its sender's address, or peek at it without consuming it.
     *
     * A receive that finds no datagram after all, as when another receive took it first, waits again; one that finds
     * nothing while the socket stays readable fails, since waiting again would wake at once
     * ({@see Internal\receive_from_stream()}).
     *
     * @param positive-int $maxBytes
     * @param bool $peek Whether to leave the datagram queued.
     *
     * @return array{string, Network\Address} [data, sender_address]
     *
     * @throws Network\Exception\RuntimeException If the socket stays readable while the receive finds nothing.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed, or is closed while the call
     *                                             waits.
     */
    private function read(int $maxBytes, bool $peek, CancellationTokenInterface $cancellation): array
    {
        $stream = $this->getResource();

        while (true) {
            $this->waits->readable($stream, $cancellation);
            // The socket may have been closed once the wait had ended, before this fiber ran again.
            $stream = $this->getResource();

            $received = Internal\receive_from_stream($stream, $maxBytes, $peek);
            if (null !== $received) {
                return [$received[0], Internal\parse_address($received[1])];
            }
        }
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
