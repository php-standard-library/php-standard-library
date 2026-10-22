<?php

declare(strict_types=1);

namespace Psl\UDP\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Revolt\EventLoop;
use Throwable;

use function spl_object_id;

/**
 * The waits of one UDP socket that have not ended yet: receives and peeks waiting for a datagram, and sends waiting
 * until the socket can take one.
 *
 * Each wait has a record of its own, which its watcher, its cancellation listener and {@see failAll()} settle,
 * whichever comes first; the others are then ignored. The socket calls {@see failAll()} before it closes its stream,
 * so that every waiting call fails once and no watcher outlives the stream.
 *
 * @internal
 */
final class PendingWaits
{
    /**
     * Each wait that has not ended, with its watcher, by the object identifier of its record.
     *
     * @var array<int, array{Waiter, string}>
     */
    private array $waits = [];

    /**
     * Wait for the stream to become readable.
     *
     * @param resource $stream
     *
     * @throws CancelledException If $cancellation is cancelled before the stream becomes readable.
     * @throws Throwable The failure given to {@see failAll()} while the call waits.
     */
    public function readable(mixed $stream, CancellationTokenInterface $cancellation): void
    {
        $this->wait($stream, true, $cancellation);
    }

    /**
     * Wait for the stream to become writable.
     *
     * @param resource $stream
     *
     * @throws CancelledException If $cancellation is cancelled before the stream becomes writable.
     * @throws Throwable The failure given to {@see failAll()} while the call waits.
     */
    public function writable(mixed $stream, CancellationTokenInterface $cancellation): void
    {
        $this->wait($stream, false, $cancellation);
    }

    /**
     * End every wait with the given failure, and cancel its watcher at once.
     *
     * A wait that something else has already ended keeps its outcome; its watcher is cancelled all the same.
     */
    public function failAll(Throwable $failure): void
    {
        $waits = $this->waits;
        $this->waits = [];
        foreach ($waits as [$waiter, $watcher]) {
            EventLoop::cancel($watcher);
            $waiter->fail($failure);
        }
    }

    /**
     * Wait for the stream to become readable or writable, or for the wait to end otherwise.
     *
     * The wait has its own record, which the watcher, the cancellation listener and {@see failAll()} settle: the
     * listener may run during subscribe(), before the wait has suspended, or after the wait has ended, when the fiber
     * may already be waiting for something else.
     *
     * @param resource $stream
     * @param bool $readable Whether to wait for the stream to become readable, rather than writable.
     *
     * @throws CancelledException If $cancellation is cancelled before the stream becomes ready.
     * @throws Throwable The failure given to {@see failAll()} while the call waits.
     */
    private function wait(mixed $stream, bool $readable, CancellationTokenInterface $cancellation): void
    {
        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        $waiter = new Waiter();
        $callback = static function () use ($waiter): void {
            $waiter->resume();
        };
        $watcher = $readable ? EventLoop::onReadable($stream, $callback) : EventLoop::onWritable($stream, $callback);
        $key = spl_object_id($waiter);
        $this->waits[$key] = [$waiter, $watcher];

        $id = null;

        try {
            if ($cancellable) {
                $id = $cancellation->subscribe(static function (CancelledException $exception) use ($waiter): void {
                    $waiter->fail($exception);
                });
            }

            $waiter->wait();
        } finally {
            unset($this->waits[$key]);
            EventLoop::cancel($watcher);
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }
        }
    }
}
