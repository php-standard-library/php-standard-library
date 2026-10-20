<?php

declare(strict_types=1);

namespace Psl\TCP;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\Network;
use Revolt\EventLoop;

use function array_filter;
use function array_pop;
use function array_values;
use function is_resource;
use function spl_object_id;

/**
 * A connection pool that reuses idle TCP connections.
 *
 * When a connection is checked in, it becomes available for reuse. An idle timer
 * is started; if the connection is not checked out before the timer fires, it is
 * closed and removed from the pool.
 *
 * Usage:
 *   $pool = new SocketPool();
 *   $stream = $pool->checkout('example.com', 80);
 *   // ... use stream ...
 *   $pool->checkin($stream);  // return for reuse
 *   $stream2 = $pool->checkout('example.com', 80); // reuses the same connection
 *
 * @api
 */
final class SocketPool implements SocketPoolInterface
{
    /**
     * @var array<string, list<array{StreamInterface, string}>>
     *     Map of "host:port" => list of [stream, idle_timer_watcher_id]
     */
    private array $idle = [];

    /**
     * @var array<int, string>
     *     Map of spl_object_id => "host:port" key for checked-out streams
     */
    private array $checkedOut = [];

    private readonly Duration $idleTimeout;

    private bool $closed = false;

    public function __construct(
        private readonly ConnectorInterface $connector = new Connector(),
        null|Duration $idleTimeout = null,
    ) {
        $this->idleTimeout = $idleTimeout ?? Duration::seconds(10);
    }

    public function checkout(
        string $host,
        int $port,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        $key = Network\Address::tcp($host, $port)->toString();

        // Try to reuse an idle connection
        while (isset($this->idle[$key]) && $this->idle[$key] !== []) {
            $entry = array_pop($this->idle[$key]);
            [$stream, $timerId] = $entry;
            EventLoop::cancel($timerId);

            if ($this->idle[$key] === []) {
                unset($this->idle[$key]);
            }

            // Verify connection is still valid
            $resource = $stream->getStream();
            if ($resource !== null && is_resource($resource)) {
                $this->checkedOut[spl_object_id($stream)] = $key;
                return $stream;
            }

            // Connection is dead, close and try next
            $stream->close();
        }

        // No idle connection available, create a new one
        $stream = $this->connector->connect($host, $port, $cancellation);
        $this->checkedOut[spl_object_id($stream)] = $key;

        return $stream;
    }

    public function checkin(StreamInterface $stream): void
    {
        $id = spl_object_id($stream);
        $key = $this->checkedOut[$id] ?? null;
        if ($key === null) {
            return;
        }

        unset($this->checkedOut[$id]);

        // Verify connection is still valid before pooling
        $resource = $stream->getStream();
        if ($resource === null || !is_resource($resource)) {
            $stream->close();
            return;
        }

        // Start idle timer
        $timerId = EventLoop::delay($this->idleTimeout->getTotalSeconds(), function () use ($stream, $key): void {
            $this->removeFromIdle($stream, $key);
            $stream->close();
        });

        $this->idle[$key] ??= [];
        $this->idle[$key][] = [$stream, $timerId];
    }

    public function clear(StreamInterface $stream): void
    {
        $id = spl_object_id($stream);

        // Remove from checked-out tracking
        $key = $this->checkedOut[$id] ?? null;
        if ($key !== null) {
            unset($this->checkedOut[$id]);
        }

        // Remove from idle pool
        if ($key !== null) {
            $this->removeFromIdle($stream, $key);
        }

        $stream->close();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;

        foreach ($this->idle as $entries) {
            foreach ($entries as [$stream, $timerId]) {
                EventLoop::cancel($timerId);
                $stream->close();
            }
        }

        $this->idle = [];
    }

    private function removeFromIdle(StreamInterface $stream, string $key): void
    {
        if (!isset($this->idle[$key])) {
            return;
        }

        $targetId = spl_object_id($stream);
        $this->idle[$key] = array_values(array_filter($this->idle[$key], static function (array $entry) use (
            $targetId,
        ): bool {
            [$s, $timerId] = $entry;
            if (spl_object_id($s) === $targetId) {
                EventLoop::cancel($timerId);
                return false;
            }

            // @codeCoverageIgnoreStart
            return true;
            // @codeCoverageIgnoreEnd
        }));

        if ($this->idle[$key] === []) {
            unset($this->idle[$key]);
        }
    }
}
