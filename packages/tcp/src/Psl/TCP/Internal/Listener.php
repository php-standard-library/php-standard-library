<?php

declare(strict_types=1);

namespace Psl\TCP\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Psl\TCP;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Socket;

use function array_search;
use function array_shift;
use function array_values;
use function fclose;
use function function_exists;
use function is_resource;
use function socket_accept;
use function socket_close;
use function socket_export_stream;
use function socket_import_stream;
use function socket_set_nonblock;
use function socket_set_option;
use function stream_context_get_options;
use function stream_socket_accept;

use const SOL_TCP;
use const TCP_NODELAY;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class Listener implements TCP\ListenerInterface
{
    /**
     * @var closed-resource|resource|null $impl
     */
    private mixed $impl;

    private string $watcher;

    private readonly Network\Address $localAddress;

    /**
     * The listening socket imported into ext-sockets, or null when ext-sockets is unavailable.
     *
     * Streams returned by `stream_socket_accept()` share the listening socket's stream
     * context (php-src `main/streams/xp_socket.c`, `php_tcp_sockop_accept`, and
     * `ext/openssl/xp_ssl.c`, `php_openssl_tcp_sockop_accept`). Everything written to that
     * context for one connection, such as TLS options and captured peer certificates, is
     * visible on every other connection of the same listener. A stream created by
     * `socket_export_stream()` starts without a context (`ext/sockets/sockets.c`), so the
     * first option written to it allocates a context private to that connection
     * (`ext/standard/streamsfuncs.c`, `decode_context_param`).
     */
    private readonly null|Socket $socket;

    /**
     * Whether `TCP_NODELAY` must be set on accepted connections.
     *
     * `stream_socket_accept()` applies the listener's `socket.tcp_nodelay` context option
     * itself (`main/network.c`, `php_network_accept_incoming`); `socket_accept()` does not.
     */
    private readonly bool $noDelay;

    private const int DEFAULT_IDLE_CONNECTIONS = 256;

    /**
     * Raw sockets waiting to be consumed by accept().
     *
     * @var list<array{resource, Network\Address}>
     */
    private array $backlog = [];

    /**
     * Current backlog size (avoids count() overhead).
     */
    private int $backlogSize = 0;

    /**
     * Suspensions waiting for the next accepted stream.
     *
     * @var list<Suspension<array{resource, Network\Address}>>
     */
    private array $acceptors = [];

    /**
     * Maximum number of connections that can be buffered in the backlog.
     */
    private readonly int $capacity;

    /**
     * Whether the watcher is currently paused due to backlog being full.
     */
    private bool $paused = false;

    /**
     * @param resource $impl
     * @param int<1, max> $idleConnections
     */
    public function __construct(mixed $impl, int $idleConnections = self::DEFAULT_IDLE_CONNECTIONS)
    {
        $this->impl = $impl;
        $this->capacity = $idleConnections;
        $this->localAddress = Network\Internal\get_sock_name($impl);
        $this->socket = self::importSocket($impl);
        $this->noDelay = (stream_context_get_options($impl)['socket']['tcp_nodelay'] ?? false) === true;

        $this->watcher = EventLoop::onReadable($impl, function (string $watcher, mixed $resource): void {
            while (true) {
                $sock = $this->acceptSocket($resource);
                if ($sock === false) {
                    return;
                }

                if ($this->acceptors !== []) {
                    $acceptor = array_shift($this->acceptors);
                    $acceptor->resume($sock);

                    continue;
                }

                $this->backlog[] = $sock;
                $this->backlogSize++;

                if ($this->backlogSize >= $this->capacity) {
                    $this->paused = true;
                    EventLoop::disable($watcher);

                    return;
                }
            }
        });

        EventLoop::disable($this->watcher);
        $this->paused = true;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): TCP\StreamInterface
    {
        if (null === $this->impl) {
            throw new Network\Exception\AlreadyStoppedException('Server socket has already been stopped.');
        }

        if ($this->paused) {
            $this->paused = false;
            EventLoop::enable($this->watcher);
        }

        if ($this->backlog !== []) {
            [$socket, $peerAddress] = array_shift($this->backlog);
            $this->backlogSize--;

            return new Stream($socket, $this->localAddress, $peerAddress);
        }

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        /** @var Suspension<array{resource, Network\Address}> $suspension */
        $suspension = EventLoop::getSuspension();
        $this->acceptors[] = $suspension;

        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                $key = array_search($suspension, $this->acceptors, true);
                if ($key !== false) {
                    unset($this->acceptors[$key]);
                    $this->acceptors = array_values($this->acceptors);
                    $suspension->throw($e);
                }
            });

            try {
                [$socket, $peerAddress] = $suspension->suspend();

                return new Stream($socket, $this->localAddress, $peerAddress);
            } finally {
                $cancellation->unsubscribe($id);
            }
        }

        [$socket, $peerAddress] = $suspension->suspend();

        return new Stream($socket, $this->localAddress, $peerAddress);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getLocalAddress(): Network\Address
    {
        return $this->localAddress;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function isClosed(): bool
    {
        return null === $this->impl;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        EventLoop::cancel($this->watcher);
        if (null === $this->impl) {
            return;
        }

        $resource = $this->impl;
        while ($this->acceptors !== [] && is_resource($resource)) {
            $sock = $this->acceptSocket($resource);
            if ($sock === false) {
                break;
            }

            $acceptor = array_shift($this->acceptors);
            if (null === $acceptor) {
                $this->backlog[] = $sock;
                $this->backlogSize++;

                break;
            }

            $this->acceptors = array_values($this->acceptors);
            $acceptor->resume($sock);
        }

        while ($this->acceptors !== [] && $this->backlog !== []) {
            $sock = array_shift($this->backlog);
            $this->backlogSize--;
            $acceptor = array_shift($this->acceptors);
            $this->acceptors = array_values($this->acceptors);
            $acceptor->resume($sock);
        }

        $acceptors = $this->acceptors;
        $this->acceptors = [];
        $exception = new Network\Exception\AlreadyStoppedException('Server socket has been stopped.');
        foreach ($acceptors as $acceptor) {
            $acceptor->throw($exception);
        }

        foreach ($this->backlog as [$sock]) {
            if (!is_resource($sock)) {
                continue;
            }

            fclose($sock);
        }

        $this->backlog = [];
        $this->backlogSize = 0;

        $this->impl = null;
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Import the listening socket into ext-sockets so that connections can be accepted
     * with `socket_accept()`.
     *
     * Returns null when ext-sockets is unavailable; connections are then accepted with
     * `stream_socket_accept()` and share the listener's stream context.
     *
     * @param resource $impl
     */
    private static function importSocket(mixed $impl): null|Socket
    {
        if (
            !function_exists('socket_import_stream')
            || !function_exists('socket_accept')
            || !function_exists('socket_export_stream')
        ) {
            return null;
        }

        $socket = @socket_import_stream($impl);
        if ($socket === false || !@socket_set_nonblock($socket)) {
            return null;
        }

        return $socket;
    }

    /**
     * Accept one pending connection without blocking, together with its peer address.
     *
     * With ext-sockets, the connection is accepted with `socket_accept()` and exported into a
     * stream that owns a private stream context. Without it, `stream_socket_accept()` is used
     * and the returned stream shares the listener's context.
     *
     * The peer address is captured right after accepting. A connection whose peer address
     * cannot be read (for example one the client already reset) is closed and skipped, so
     * one misbehaving client never fails the listener.
     *
     * @param resource $listener
     *
     * @return false|array{resource, Network\Address} False if no connection is pending.
     */
    private function acceptSocket(mixed $listener): false|array
    {
        while (true) {
            $peerName = null;
            if (null === $this->socket) {
                $stream = @stream_socket_accept($listener, 0.0, $peerName);
                if (false === $stream) {
                    return false;
                }
            } else {
                $connection = @socket_accept($this->socket);
                if ($connection === false) {
                    return false;
                }

                if ($this->noDelay) {
                    @socket_set_option($connection, SOL_TCP, TCP_NODELAY, 1);
                }

                $stream = @socket_export_stream($connection);
                if (!is_resource($stream)) {
                    socket_close($connection);

                    continue;
                }
                // The exported stream owns the descriptor from here on; dropping the Socket object
                // only releases its reference to the stream (`ext/sockets/sockets.c`, `socket_free_obj`).
            }

            try {
                return [
                    $stream,
                    Network\Internal\get_peer_name($stream, $peerName !== null && $peerName !== '' ? $peerName : null),
                ];
            } catch (Network\Exception\RuntimeException) {
                fclose($stream);
            }
        }
    }
}
