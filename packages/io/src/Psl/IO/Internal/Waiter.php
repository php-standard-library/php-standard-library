<?php

declare(strict_types=1);

namespace Psl\IO\Internal;

use Psl\Async;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * One wait of a stream handle for its stream to become ready.
 *
 * Whatever ends the wait first settles it, the stream becoming ready, a cancellation or the handle closing, and
 * everything after that is ignored. Each wait has its own record because the event loop hands the same suspension
 * to every wait of a fiber: a cancellation listener that reached the suspension directly could end a later wait of
 * the same fiber. A wait that settles before it has suspended keeps its failure and throws it without suspending:
 * a suspension cannot be resumed before it has suspended.
 *
 * @internal
 */
final class Waiter
{
    private bool $settled = false;

    private bool $suspended = false;

    private null|Throwable $failure = null;

    private function __construct(
        private readonly Suspension $suspension,
    ) {}

    /**
     * Wait until the stream watched by `$watcher` is ready.
     *
     * `$current` is where the watcher's callback finds the waiting record: it holds this wait's record while it
     * waits, and null afterwards.
     *
     * @param-out null|Waiter $current
     *
     * @throws Async\Exception\CancelledException If `$cancellation` is cancelled before the stream is ready.
     * @throws Throwable The failure the wait was ended with, see {@see fail()}.
     */
    public static function await(
        string $watcher,
        null|Waiter &$current,
        Async\CancellationTokenInterface $cancellation,
    ): void {
        $cancellable = $cancellation->cancellable;
        if ($cancellable) {
            $cancellation->throwIfCancelled();
        }

        $waiter = new self(EventLoop::getSuspension());
        $current = $waiter;
        EventLoop::enable($watcher);

        $id = null;

        try {
            if ($cancellable) {
                // The listener ends only this wait. It may run during subscribe(), before the wait has suspended,
                // or after the wait has ended, when the fiber may already be waiting again.
                $id = $cancellation->subscribe(static function (Async\Exception\CancelledException $e) use (
                    $waiter,
                ): void {
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            if ($current === $waiter) {
                $current = null;
            }

            EventLoop::disable($watcher);
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }
        }
    }

    /**
     * End the wait: the stream is ready.
     */
    public function resume(): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        if ($this->suspended) {
            $this->suspension->resume();
        }
    }

    /**
     * End the wait with the given failure.
     */
    public function fail(Throwable $failure): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        if ($this->suspended) {
            $this->suspension->throw($failure);

            return;
        }

        $this->failure = $failure;
    }

    /**
     * Suspend until the wait is settled, unless it already is.
     *
     * @throws Throwable The failure that settled the wait.
     */
    private function wait(): void
    {
        if ($this->settled) {
            if (null !== $this->failure) {
                throw $this->failure;
            }

            return;
        }

        $this->suspended = true;

        try {
            $this->suspension->suspend();
        } finally {
            $this->suspended = false;
            $this->settled = true;
        }
    }
}
