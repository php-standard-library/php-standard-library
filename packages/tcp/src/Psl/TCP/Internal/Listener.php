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
use Socket;

use function array_shift;
use function array_unshift;
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
     * The accept() calls waiting for the next connection, oldest first.
     *
     * @var list<AcceptWaiter>
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
     * Wrap a listening TCP socket, taking ownership of it.
     *
     * The listener accepts nothing until the first accept() call enables its watcher. From then on, the watcher
     * hands each connection to the oldest waiting accept() call that has not ended yet, skipping a call that a
     * cancellation ended, and keeps the connections that no call takes in a backlog. Once the backlog holds
     * $idleConnections connections, the watcher pauses until the next accept() call. With ext-sockets, connections
     * are accepted with `socket_accept()`, each with a stream context of its own (see {@see Listener::$socket}).
     *
     * @param resource $impl The listening socket, which the listener closes once it is closed or destroyed.
     * @param int<1, max> $idleConnections The most connections the backlog holds before the watcher pauses.
     */
    public function __construct(mixed $impl, int $idleConnections = self::DEFAULT_IDLE_CONNECTIONS)
    {
        $this->impl = $impl;
        $this->capacity = $idleConnections;
        $this->localAddress = Network\Internal\get_sock_name($impl);
        $this->socket = self::importSocket($impl);
        $this->noDelay = (stream_context_get_options($impl)['socket']['tcp_nodelay'] ?? false) === true;

        // The callback must not reference the listener: the event loop would keep it alive forever, so a
        // listener that is no longer used would never be closed by `__destruct()`, and once accept() has
        // enabled the watcher, it would keep the event loop running. The callback shares the accept state
        // by reference instead, which still keeps the records of waiting accept() calls alive.
        $acceptors = &$this->acceptors;
        $backlog = &$this->backlog;
        $backlogSize = &$this->backlogSize;
        $paused = &$this->paused;
        $capacity = $this->capacity;
        $socket = $this->socket;
        $noDelay = $this->noDelay;

        $this->watcher = EventLoop::onReadable($impl, static function (string $watcher, mixed $resource) use (
            &$acceptors,
            &$backlog,
            &$backlogSize,
            &$paused,
            $capacity,
            $socket,
            $noDelay,
        ): void {
            while (true) {
                $sock = self::acceptSocket($resource, $socket, $noDelay);
                if ($sock === false) {
                    return;
                }

                if ($acceptors !== [] && AcceptWaiter::handOver($acceptors, $sock)) {
                    continue;
                }

                $backlog[] = $sock;
                $backlogSize++;

                if ($backlogSize >= $capacity) {
                    $paused = true;
                    EventLoop::disable($watcher);

                    return;
                }
            }
        });

        EventLoop::disable($this->watcher);
        $this->paused = true;
    }

    /**
     * Return the oldest connection in the backlog, or wait for the next one.
     *
     * The wait has a record of its own, which the watcher, the cancellation listener and {@see close()} settle,
     * whichever comes first; the others are then ignored. A call that a cancellation ended leaves the queue of
     * waiting calls once its fiber runs again, and a connection that arrives meanwhile goes to the next waiting
     * call, or to the backlog.
     *
     * @throws Network\Exception\AlreadyStoppedException If the listener is closed before or while the call waits.
     * @throws CancelledException If $cancellation is cancelled before a connection arrives.
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

        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        // The wait has its own record, which the watcher, the cancellation listener and close() settle: the listener
        // may run during subscribe(), before the wait has suspended, or after the wait has ended, when the fiber may
        // already be waiting for something else.
        $waiter = new AcceptWaiter();
        $this->acceptors[] = $waiter;

        $id = null;
        $accepted = false;

        try {
            if ($cancellable) {
                $id = $cancellation->subscribe(static function (CancelledException $e) use ($waiter): void {
                    $waiter->fail($e);
                });
            }

            [$socket, $peerAddress] = $waiter->wait();
            $accepted = true;
        } finally {
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }

            // A wait that took a connection has already left the queue; one that ended otherwise leaves it now.
            if (!$accepted) {
                AcceptWaiter::forget($this->acceptors, $waiter);
            }
        }

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
     * Stop listening, and settle every waiting accept() call.
     *
     * Before the listening socket closes, the connections already pending on it and those in the backlog go to the
     * waiting calls, oldest first, skipping a call that a cancellation ended. The calls left over fail with
     * `AlreadyStoppedException`, and the connections left over are closed. The connections that accept() returned
     * stay open. Closing a closed listener does nothing.
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
            $sock = self::acceptSocket($resource, $this->socket, $this->noDelay);
            if ($sock === false) {
                break;
            }

            if (!AcceptWaiter::handOver($this->acceptors, $sock)) {
                // Every waiting call had already ended; the connection is closed with the backlog below.
                $this->backlog[] = $sock;
                $this->backlogSize++;
            }
        }

        while ($this->acceptors !== [] && $this->backlog !== []) {
            $sock = array_shift($this->backlog);
            if (!AcceptWaiter::handOver($this->acceptors, $sock)) {
                array_unshift($this->backlog, $sock);

                break;
            }

            $this->backlogSize--;
        }

        $acceptors = $this->acceptors;
        $this->acceptors = [];
        $exception = new Network\Exception\AlreadyStoppedException('Server socket has been stopped.');
        foreach ($acceptors as $acceptor) {
            $acceptor->fail($exception);
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
     * @param null|Socket $socket The listening socket imported into ext-sockets, see {@see Listener::$socket}.
     * @param bool $noDelay Whether to set `TCP_NODELAY` on the connection, see {@see Listener::$noDelay}.
     *
     * @return false|array{resource, Network\Address} False if no connection is pending.
     */
    private static function acceptSocket(mixed $listener, null|Socket $socket, bool $noDelay): false|array
    {
        while (true) {
            $peerName = null;
            if (null === $socket) {
                $stream = @stream_socket_accept($listener, 0.0, $peerName);
                if (false === $stream) {
                    return false;
                }
            } else {
                $connection = @socket_accept($socket);
                if ($connection === false) {
                    return false;
                }

                if ($noDelay) {
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
