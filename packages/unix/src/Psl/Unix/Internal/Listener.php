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
use Revolt\EventLoop\Suspension;

use function array_search;
use function array_shift;
use function array_values;
use function error_get_last;
use function fclose;
use function is_resource;
use function str_contains;
use function stream_socket_accept;

/**
 * @internal
 *
 * @codeCoverageIgnore
 *
 * @mago-expect lint:excessive-nesting
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
     * Suspensions waiting for the next accepted stream.
     *
     * @var list<Suspension<resource>>
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
     * @param resource $impl
     * @param Network\Address $boundAddress The address the socket was bound to, reported as the local
     *                                     address when the socket's name cannot be read from it.
     * @param int<1, max> $idleConnections
     */
    public function __construct(
        mixed $impl,
        Network\Address $boundAddress,
        int $idleConnections = self::DEFAULT_IDLE_CONNECTIONS,
    ) {
        $this->impl = $impl;
        $this->capacity = $idleConnections;

        try {
            $this->localAddress = Network\Internal\get_sock_name($impl);
        } catch (Network\Exception\RuntimeException) {
            // PHP does not report the name of a socket in the Linux abstract namespace,
            // as it starts with a NUL byte.
            $this->localAddress = $boundAddress;
        }

        $this->peerAddress = Network\Address::unix('<anonymous>');

        $this->watcher = EventLoop::onReadable($impl, function (string $watcher, mixed $resource): void {
            while (true) {
                $sock = @stream_socket_accept($resource, timeout: 0.0);
                if ($sock !== false) {
                    if ($this->acceptors !== []) {
                        $acceptor = array_shift($this->acceptors);
                        $acceptor->resume($sock);
                    } else {
                        $this->backlog[] = $sock;
                        $this->backlogSize++;

                        if ($this->backlogSize >= $this->capacity) {
                            $this->paused = true;
                            EventLoop::disable($watcher);
                            return;
                        }
                    }

                    continue;
                }

                // @codeCoverageIgnoreStart
                $err = error_get_last();
                if ($err !== null && !str_contains($err['message'], 'Accept failed')) {
                    $this->error = new Network\Exception\RuntimeException(
                        'Failed to accept incoming connection: ' . $err['message'],
                        $err['type'],
                    );

                    if ($this->acceptors !== []) {
                        $acceptor = array_shift($this->acceptors);
                        $acceptor->throw($this->error);
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
     * @inheritDoc
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

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        /** @var Suspension<resource> $suspension */
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
                $socket = $suspension->suspend();

                return new Stream($socket, $this->localAddress, $this->peerAddress);
            } finally {
                $cancellation->unsubscribe($id);
            }
        }

        $socket = $suspension->suspend();

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
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        EventLoop::cancel($this->watcher);
        if (null === $this->impl) {
            return;
        }

        // Drain any pending OS connections to satisfy waiting acceptors.
        while ($this->acceptors !== [] && is_resource($this->impl)) {
            $sock = @stream_socket_accept($this->impl, timeout: 0.0);
            if ($sock === false) {
                break;
            }

            $acceptor = array_shift($this->acceptors);
            $this->acceptors = array_values($this->acceptors);
            $acceptor->resume($sock);
        }

        // Feed remaining acceptors from the backlog.
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

        foreach ($this->backlog as $sock) {
            if (!is_resource($sock)) {
                continue;
            }

            fclose($sock);
        }

        $this->backlog = [];
        $this->backlogSize = 0;

        $resource = $this->impl;
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
