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
use function str_contains;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;

use const STREAM_CLIENT_CONNECT;
use const STREAM_PEEK;
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
     * @var resource|closed-resource|null
     */
    private mixed $stream;

    private readonly Network\Address $localAddress;

    /**
     * @param resource $stream
     */
    private function __construct(mixed $stream)
    {
        $this->stream = $stream;
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
     * This socket is closed after connecting. Use the returned {@see ConnectedSocket} for further communication.
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

        $localName = @stream_socket_get_name($oldStream, false);
        $bindto = $localName !== false ? $localName : '0.0.0.0:0';

        fclose($oldStream);
        $this->stream = null;

        $context = stream_context_create(['socket' => [
            'bindto' => $bindto,
        ]]);

        $address = Network\Address::udp($host, $port);
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
     * Send a datagram to a specific address.
     *
     * @return int<0, max> Number of bytes sent.
     *
     * @throws Network\Exception\RuntimeException If the send fails.
     * @throws Network\Exception\InvalidArgumentException If the datagram exceeds the maximum size.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
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

        Internal\wait_writable($stream, $cancellation);

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
     * @param positive-int $maxBytes
     *
     * @return array{string, Network\Address} [data, sender_address]
     *
     * @throws Network\Exception\RuntimeException If the receive fails.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
     */
    public function receiveFrom(
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): array {
        $stream = $this->getResource();

        Internal\await_readable($stream, $cancellation);

        $address = '';
        $data = @stream_socket_recvfrom($stream, $maxBytes, 0, $address);
        if ($data === false) {
            // @codeCoverageIgnoreStart
            throw new Network\Exception\RuntimeException('Failed to receive UDP datagram.');
            // @codeCoverageIgnoreEnd
        }

        return [$data, Internal\parse_address($address)];
    }

    /**
     * Peek at an incoming datagram and get the sender's address, without consuming it.
     *
     * @param positive-int $maxBytes
     *
     * @return array{string, Network\Address} [data, sender_address]
     *
     * @throws Network\Exception\RuntimeException If the peek fails.
     * @throws CancelledException If the operation is cancelled.
     * @throws IO\Exception\AlreadyClosedException If the socket has already been closed.
     */
    public function peekFrom(
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): array {
        $stream = $this->getResource();

        Internal\await_readable($stream, $cancellation);

        $address = '';
        $data = @stream_socket_recvfrom($stream, $maxBytes, STREAM_PEEK, $address);
        if ($data === false) {
            // @codeCoverageIgnoreStart
            throw new Network\Exception\RuntimeException('Failed to peek UDP datagram.');
            // @codeCoverageIgnoreEnd
        }

        return [$data, Internal\parse_address($address)];
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

    #[Override]
    public function close(): void
    {
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
