<?php

declare(strict_types=1);

namespace Psl\TCP;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Async\Scheduler;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use WeakMap;

use function array_key_last;
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
     * The idle connections, by pool key and then by object ID, each with the ID of its idle timer.
     *
     * Each bucket is in check-in order, and a checkout takes the connection checked in last. An idle connection is
     * referenced from here, so no other object can get its object ID while it is idle.
     *
     * @var array<string, array<int, array{StreamInterface, string}>>
     */
    private array $idle = [];

    /**
     * The pool key of each idle connection, by object ID.
     *
     * @var array<int, string>
     */
    private array $idleKeys = [];

    /**
     * The pool key of each checked-out connection.
     *
     * The connections themselves are the keys, held weakly: a connection that its borrower drops without checking it
     * in leaves on its own, and a connection that later gets the same object ID is not mistaken for it.
     *
     * @var WeakMap<StreamInterface, string>
     */
    private readonly WeakMap $checkedOut;

    private readonly Duration $idleTimeout;

    private bool $closed = false;

    /**
     * Create an empty pool that makes its new connections with the given connector.
     *
     * @param null|Duration $idleTimeout How long a checked-in connection stays idle in the pool before it is
     *                                   closed. Defaults to 10 seconds.
     */
    public function __construct(
        /**
         * The connector that makes each new connection of the pool.
         */
        private readonly ConnectorInterface $connector = new Connector(),
        null|Duration $idleTimeout = null,
    ) {
        $this->idleTimeout = $idleTimeout ?? Duration::seconds(10);
        $this->checkedOut = new WeakMap();
    }

    /**
     * Get a connection to the given host and port: an idle connection of the pool if one is left, or a new one.
     *
     * @param non-empty-string $host
     * @param int<0, 65535> $port
     *
     * @throws Network\Exception\RuntimeException If the connection fails.
     * @throws CancelledException If $cancellation is cancelled while connecting.
     */
    public function checkout(
        string $host,
        int $port,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        $key = Network\Address::tcp($host, $port)->toString();

        // Try to reuse an idle connection, the one checked in last first
        while (null !== ($id = array_key_last($this->idle[$key] ?? []))) {
            $stream = $this->forgetIdle($key, $id);

            // Verify connection is still valid
            $resource = $stream->getStream();
            if ($resource !== null && is_resource($resource)) {
                $this->checkedOut[$stream] = $key;
                return $stream;
            }

            // Connection is dead, close and try next
            $stream->close();
        }

        // No idle connection available, create a new one
        $stream = $this->connector->connect($host, $port, $cancellation);
        $this->checkedOut[$stream] = $key;

        return $stream;
    }

    /**
     * Return a connection that {@see checkout()} handed out to the pool for reuse.
     *
     * The connection stays idle in the pool until it is checked out again, or until the idle timeout closes it. A
     * connection the pool does not have checked out, a foreign one or one already checked in, is left alone.
     */
    public function checkin(StreamInterface $stream): void
    {
        $key = $this->checkedOut[$stream] ?? null;
        if ($key === null) {
            return;
        }

        unset($this->checkedOut[$stream]);

        // Verify connection is still valid before pooling
        $resource = $stream->getStream();
        if ($resource === null || !is_resource($resource)) {
            $stream->close();
            return;
        }

        // Start idle timer
        $id = spl_object_id($stream);
        $timerId = Scheduler::delay($this->idleTimeout, function () use ($key, $id): void {
            self::closeExpiredConnection($this->forgetIdle($key, $id));
        });

        $this->idle[$key][$id] = [$stream, $timerId];
        $this->idleKeys[$id] = $key;
    }

    /**
     * Remove a connection from the pool for good, and close it.
     *
     * The connection leaves the pool whether it is checked out or idle, and an idle connection's timer is cancelled.
     * The connection is closed even when the pool does not know it.
     *
     * @throws IO\Exception\RuntimeException If closing the connection failed.
     */
    public function clear(StreamInterface $stream): void
    {
        unset($this->checkedOut[$stream]);

        $id = spl_object_id($stream);
        $key = $this->idleKeys[$id] ?? null;
        if (null !== $key) {
            $this->forgetIdle($key, $id);
        }

        $stream->close();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close the pool: close every idle connection, after cancelling every idle timer.
     *
     * The pool attempts each of those closes even after one fails, and throws the first failure once it has attempted
     * them all. The connections that are checked out stay open.
     *
     * @throws IO\Exception\RuntimeException If closing an idle connection failed.
     */
    public function close(): void
    {
        $this->closed = true;

        $idle = $this->idle;
        $this->idle = [];
        $this->idleKeys = [];

        // Cancel every idle timer before closing anything, so that no failing close can leave one running.
        foreach ($idle as $entries) {
            foreach ($entries as [, $timerId]) {
                Scheduler::cancel($timerId);
            }
        }

        $failure = null;
        foreach ($idle as $entries) {
            foreach ($entries as [$stream]) {
                try {
                    $stream->close();
                } catch (IO\Exception\RuntimeException $e) {
                    $failure ??= $e;
                }
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Take an idle connection out of the pool, and cancel its idle timer.
     *
     * @param string $key The pool key of the connection, its key in {@see SocketPool::$idle}.
     * @param int $id The object ID of the connection, its key in the bucket.
     *
     * @return StreamInterface The connection, which the caller now owns.
     */
    private function forgetIdle(string $key, int $id): StreamInterface
    {
        [$stream, $timerId] = $this->idle[$key][$id];
        unset($this->idle[$key][$id], $this->idleKeys[$id]);
        if ([] === $this->idle[$key]) {
            unset($this->idle[$key]);
        }

        Scheduler::cancel($timerId);

        return $stream;
    }

    /**
     * Close a connection whose idle timeout has passed.
     *
     * An event loop callback closes it, so nobody can be told that closing it failed: an expected close failure is
     * suppressed, as a destructor would, while any other failure still reaches the event loop.
     */
    private static function closeExpiredConnection(StreamInterface $connection): void
    {
        try {
            $connection->close();
        } catch (IO\Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause - Nobody can be told of an expected close failure here (E11).
        }
    }
}
