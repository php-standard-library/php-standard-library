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
final class Listener implements TCP\ListenerInterface
{
    /**
     * @var closed-resource|resource|null $impl
     */
    private mixed $impl;

    private string $watcher;

    private readonly Network\Address $localAddress;

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
     * Error from the accept callback, if any.
     */
    private null|Network\Exception\RuntimeException $error = null;

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

        $this->watcher = EventLoop::onReadable($impl, function (string $watcher, mixed $resource): void {
            while (true) {
                $sock = self::acceptSocket($resource);
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

        if ($this->error !== null) {
            throw $this->error;
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

        while ($this->acceptors !== [] && is_resource($this->impl)) {
            $sock = self::acceptSocket($this->impl);
            if ($sock === false) {
                break;
            }

            $acceptor = array_shift($this->acceptors);
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

        $resource = $this->impl;
        $this->impl = null;
        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * @param resource $resource
     *
     * @return false|array{resource, Network\Address}
     */
    private static function acceptSocket(mixed $resource): false|array
    {
        $peerName = null;
        while (false !== ($socket = @stream_socket_accept($resource, 0.0, $peerName))) {
            try {
                return [$socket, Network\Internal\get_peer_name($socket, $peerName !== '' ? $peerName : null)];
            } catch (Network\Exception\RuntimeException) {
                fclose($socket);
            }
        }

        return false;
    }

    public function __destruct()
    {
        $this->close();
    }
}
