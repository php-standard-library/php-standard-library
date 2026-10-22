<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

use function array_search;
use function array_shift;
use function array_values;

/**
 * One `accept()` call of a Unix listener, waiting for a connection.
 *
 * Whatever ends the wait first settles it, a connection, a cancellation, the listener failing or the listener
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
     *
     * @var null|resource
     */
    private mixed $connection = null;

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
     * Hand a connection to the oldest waiting accept() call that has not ended yet.
     *
     * A call leaves the queue once it has a connection. One that a cancellation has ended may still be queued until
     * its fiber runs again; it is skipped, and leaves the queue too.
     *
     * @param list<AcceptWaiter> $acceptors The waiting calls, oldest first.
     * @param resource $connection The accepted socket.
     *
     * @param-out list<AcceptWaiter> $acceptors
     *
     * @return bool Whether a call took the connection; if none did, it is still the caller's.
     */
    public static function handOver(array &$acceptors, mixed $connection): bool
    {
        while ($acceptors !== []) {
            $acceptor = array_shift($acceptors);
            if ($acceptor->resume($connection)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take the given wait out of the queue of waiting accept() calls, if it is still there.
     *
     * @param list<AcceptWaiter> $acceptors The waiting calls, oldest first.
     *
     * @param-out list<AcceptWaiter> $acceptors
     */
    public static function forget(array &$acceptors, AcceptWaiter $waiter): void
    {
        $key = array_search($waiter, $acceptors, true);
        if (false !== $key) {
            unset($acceptors[$key]);
            $acceptors = array_values($acceptors);
        }
    }

    /**
     * End the wait with the given connection.
     *
     * @param resource $connection The accepted socket.
     *
     * @return bool Whether the wait took the connection; when it had already ended, the connection is still the
     *              caller's.
     */
    public function resume(mixed $connection): bool
    {
        if ($this->settled) {
            return false;
        }

        $this->settled = true;
        if ($this->suspended) {
            $this->suspension->resume($connection);
        } else {
            $this->connection = $connection;
        }

        return true;
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
     * @throws Throwable The failure that settled the wait, see {@see fail()}.
     *
     * @return resource The accepted socket.
     */
    public function wait(): mixed
    {
        if ($this->settled) {
            if (null !== $this->failure) {
                throw $this->failure;
            }

            /** @var resource */
            return $this->connection;
        }

        $this->suspended = true;

        try {
            /** @var resource */
            return $this->suspension->suspend();
        } finally {
            $this->suspended = false;
            $this->settled = true;
        }
    }
}
