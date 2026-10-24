<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\TLS\StreamInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function array_search;
use function array_shift;
use function array_values;

/**
 * One `accept()` call of a {@see \Psl\TLS\Listener}, waiting for the next handshake to end.
 *
 * Whatever ends the wait first settles it, a completed handshake, a failed one, a cancellation or the listener
 * closing, and everything after that is ignored. Each wait has its own record because the event loop hands the same
 * suspension to every wait of a fiber: a cancellation listener that reached the suspension directly could end a later
 * wait of the same fiber. A wait that settles before it has suspended keeps its outcome and returns or throws it
 * without suspending: a suspension cannot be resumed before it has suspended.
 *
 * @internal
 */
final class AcceptWaiter
{
    /**
     * The suspension of the fiber that waits.
     */
    private readonly Suspension $suspension;

    /**
     * Whether something has ended the wait.
     */
    private bool $settled = false;

    /**
     * Whether the waiting fiber is suspended, so that ending the wait resumes it.
     */
    private bool $suspended = false;

    /**
     * The connection that ended the wait before it suspended, returned once it would have.
     */
    private null|StreamInterface $connection = null;

    /**
     * The failure that ended the wait before it suspended, thrown once it would have.
     */
    private null|Throwable $failure = null;

    /**
     * Create the record of a wait of the current fiber.
     */
    public function __construct()
    {
        $this->suspension = EventLoop::getSuspension();
    }

    /**
     * Hand the outcome of a handshake to the oldest waiting accept() call that has not ended yet.
     *
     * A call leaves the queue once it has an outcome. One that a cancellation has ended may still be queued until its
     * fiber runs again; it is skipped, and leaves the queue too.
     *
     * @param list<AcceptWaiter> $waiters The waiting calls, oldest first.
     * @param StreamInterface|Throwable $outcome The connection whose handshake completed, or the failure that ended
     *                                           a handshake.
     * @param-out list<AcceptWaiter> $waiters
     *
     * @return bool Whether a call took the outcome; if none did, it is still the caller's.
     */
    public static function handOver(array &$waiters, StreamInterface|Throwable $outcome): bool
    {
        while ($waiters !== []) {
            $waiter = array_shift($waiters);
            if ($waiter->settle($outcome)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take the given wait out of the queue of waiting accept() calls, if it is still there.
     *
     * @param list<AcceptWaiter> $waiters The waiting calls, oldest first.
     * @param-out list<AcceptWaiter> $waiters
     *
     * @return bool Whether the wait was still queued, which is the case when no outcome was handed to it.
     */
    public static function forget(array &$waiters, AcceptWaiter $waiter): bool
    {
        $key = array_search($waiter, $waiters, true);
        if (false === $key) {
            return false;
        }

        unset($waiters[$key]);
        $waiters = array_values($waiters);

        return true;
    }

    /**
     * End the wait with the given outcome: the call returns the connection, or throws the failure.
     *
     * @return bool Whether the wait took the outcome; when it had already ended, the outcome is still the caller's.
     */
    public function settle(StreamInterface|Throwable $outcome): bool
    {
        if ($this->settled) {
            return false;
        }

        $this->settled = true;
        if ($outcome instanceof Throwable) {
            if ($this->suspended) {
                $this->suspension->throw($outcome);
            } else {
                $this->failure = $outcome;
            }

            return true;
        }

        if ($this->suspended) {
            $this->suspension->resume($outcome);
        } else {
            $this->connection = $outcome;
        }

        return true;
    }

    /**
     * Suspend until the wait is settled, unless it already is.
     *
     * @throws Throwable The failure that settled the wait, see {@see settle()}.
     *
     * @return StreamInterface The connection whose handshake completed.
     */
    public function wait(): StreamInterface
    {
        if ($this->settled) {
            if (null !== $this->failure) {
                throw $this->failure;
            }

            /** @var StreamInterface */
            return $this->connection;
        }

        $this->suspended = true;

        try {
            /** @var StreamInterface */
            return $this->suspension->suspend();
        } finally {
            $this->suspended = false;
            $this->settled = true;
        }
    }
}
