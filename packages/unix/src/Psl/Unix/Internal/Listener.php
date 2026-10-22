<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Psl\Unix;
use Revolt\EventLoop;

use function array_shift;
use function array_unshift;
use function error_get_last;
use function fclose;
use function is_resource;
use function str_contains;
use function stream_socket_accept;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class Listener implements Unix\ListenerInterface
{
    private const int DEFAULT_IDLE_CONNECTIONS = 256;

    /**
     * @var closed-resource|resource|null $impl
     */
    private mixed $impl;

    private string $watcher;

    private readonly Network\Address $localAddress;

    private readonly Network\Address $peerAddress;

    /**
     * Raw sockets waiting to be consumed by accept().
     *
     * @var list<resource>
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
     * Error from the accept callback, if any.
     */
    private null|Network\Exception\RuntimeException $error = null;

    /**
     * Maximum number of connections that can be buffered in the backlog.
     */
    private readonly int $capacity;

    /**
     * Whether the watcher is currently paused.
     */
    private bool $paused = false;

    /**
     * Wrap a listening Unix-domain socket, taking ownership of it.
     *
     * The listener accepts nothing until the first accept() call enables its watcher, so that a process can fork
     * between listen() and accept() without losing connections. From then on, the watcher hands each connection to
     * the oldest waiting accept() call that has not ended yet, skipping a call that a cancellation ended, and keeps
     * the connections that no call takes in a backlog. Once the backlog holds $idleConnections connections, the
     * watcher pauses until the next accept() call.
     *
     * An accept that fails with any error but PHP's accept failure stops the listener: every waiting accept() call
     * fails with that error, and so does every later one.
     *
     * @param resource $impl The listening socket, which the listener closes once it is closed or destroyed.
     * @param Network\Address $boundAddress The address the socket was bound to, reported as the local
     *                                     address when the socket's name cannot be read from it.
     * @param int<1, max> $idleConnections The most connections the backlog holds before the watcher pauses.
     */
    public function __construct(
        mixed $impl,
        Network\Address $boundAddress,
        int $idleConnections = self::DEFAULT_IDLE_CONNECTIONS,
    ) {
        $this->impl = $impl;
        $this->capacity = $idleConnections;

        // PHP does not report the name of a socket in the Linux abstract namespace,
        // as it starts with a NUL byte.
        $this->localAddress = namespace\get_address($impl, peer: false) ?? $boundAddress;

        $this->peerAddress = Network\Address::unix('<anonymous>');

        // The callback must not reference the listener: the event loop would keep it alive forever, so a
        // listener that is no longer used would never be closed by `__destruct()`, and once accept() has
        // enabled the watcher, it would keep the event loop running. The callback shares the accept state
        // by reference instead, which still keeps the records of waiting accept() calls alive.
        $acceptors = &$this->acceptors;
        $backlog = &$this->backlog;
        $backlogSize = &$this->backlogSize;
        $paused = &$this->paused;
        $error = &$this->error;
        $capacity = $this->capacity;

        $this->watcher = EventLoop::onReadable($impl, static function (string $watcher, mixed $resource) use (
            &$acceptors,
            &$backlog,
            &$backlogSize,
            &$paused,
            &$error,
            $capacity,
        ): void {
            while (true) {
                $sock = @stream_socket_accept($resource, timeout: 0.0);
                if ($sock !== false) {
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

                    continue;
                }

                // @codeCoverageIgnoreStart
                $err = error_get_last();
                if ($err !== null && !str_contains($err['message'], 'Accept failed')) {
                    $error = new Network\Exception\RuntimeException(
                        'Failed to accept incoming connection: ' . $err['message'],
                        $err['type'],
                    );

                    // The listener stops accepting: every waiting call fails with the error once, and every later
                    // call with it too. The queue empties first, so that no cancellation fails a call again.
                    $failed = $acceptors;
                    $acceptors = [];
                    foreach ($failed as $acceptor) {
                        $acceptor->fail($error);
                    }

                    EventLoop::disable($watcher);
                    return;
                }

                break;
                // @codeCoverageIgnoreEnd
            }
        });

        // Start silent, don't accept OS connections until the first accept() call.
        // This allows forking between listen() and accept() without losing connections.
        EventLoop::disable($this->watcher);
        $this->paused = true;
    }

    /**
     * Return the oldest connection in the backlog, or wait for the next one.
     *
     * The wait has a record of its own, which the watcher, the cancellation listener, a failure of the listener and
     * {@see close()} settle, whichever comes first; the others are then ignored. A call that a cancellation ended
     * leaves the queue of waiting calls once its fiber runs again, and a connection that arrives meanwhile goes to the
     * next waiting call, or to the backlog.
     *
     * @throws Network\Exception\AlreadyStoppedException If the listener is closed before or while the call waits.
     * @throws Network\Exception\RuntimeException If the listener has failed to accept, before or while the call waits.
     * @throws CancelledException If $cancellation is cancelled before a connection arrives.
     */
    #[Override]
    public function accept(CancellationTokenInterface $cancellation = new NullCancellationToken()): Unix\StreamInterface
    {
        if (null === $this->impl) {
            throw new Network\Exception\AlreadyStoppedException('Server socket has already been stopped.');
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        if ($this->paused) {
            $this->paused = false;
            EventLoop::enable($this->watcher);
        }

        if ($this->backlog !== []) {
            $socket = array_shift($this->backlog);
            $this->backlogSize--;

            return new Stream($socket, $this->localAddress, $this->peerAddress);
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

            $socket = $waiter->wait();
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

        return new Stream($socket, $this->localAddress, $this->peerAddress);
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
            $sock = @stream_socket_accept($resource, timeout: 0.0);
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

        foreach ($this->backlog as $sock) {
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
}
