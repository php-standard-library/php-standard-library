<?php

declare(strict_types=1);

namespace Psl\UDP\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Revolt\EventLoop;

/**
 * Wait for a stream to become readable, with cancellation support.
 *
 * The wait ends when the stream becomes readable or when $cancellation is cancelled, whichever comes first. It has a
 * record of its own, so a cancellation that arrives once the wait has ended, while the fiber waits for something
 * else, is ignored.
 *
 * @internal
 *
 * @param resource $stream
 *
 * @throws CancelledException If the operation is cancelled.
 */
function await_readable(mixed $stream, CancellationTokenInterface $cancellation): void
{
    $cancellable = $cancellation->cancellable;
    if ($cancellable) {
        $cancellation->throwIfCancelled();
    }

    // The wait has its own record, which the watcher and the cancellation listener settle: the listener may run during
    // subscribe(), before the wait has suspended, or after the wait has ended, when the fiber may already be waiting
    // for something else.
    $waiter = new Waiter();
    $watcher = EventLoop::onReadable($stream, static function () use ($waiter): void {
        $waiter->resume();
    });

    $id = null;

    try {
        if ($cancellable) {
            $id = $cancellation->subscribe(static function (CancelledException $exception) use ($waiter): void {
                $waiter->fail($exception);
            });
        }

        $waiter->wait();
    } finally {
        EventLoop::cancel($watcher);
        if (null !== $id) {
            $cancellation->unsubscribe($id);
        }
    }
}
