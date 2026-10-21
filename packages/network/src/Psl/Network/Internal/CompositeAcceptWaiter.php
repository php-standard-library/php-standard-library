<?php

declare(strict_types=1);

namespace Psl\Network\Internal;

use Psl\Async\Scheduler;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * One wait of a {@see \Psl\Network\CompositeListener::accept()} call, until something happens that the call must
 * check: an inner accept completing, an inner listener stopping or the composite listener closing.
 *
 * Whatever ends the wait first settles it, a wake-up or a cancellation, and everything after that is ignored. Each
 * wait has its own record because the event loop hands the same suspension to every wait of a fiber: a cancellation
 * listener that reached the suspension directly could end a later wait of the same fiber. A wait that settles before
 * it has suspended keeps its outcome and returns or throws it without suspending: a suspension cannot be resumed
 * before it has suspended.
 *
 * @internal
 */
final class CompositeAcceptWaiter
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
     * The failure that ended the wait before it suspended, thrown once it would have.
     */
    private null|Throwable $failure = null;

    /**
     * Create the record of a wait of the current fiber.
     */
    public function __construct()
    {
        $this->suspension = Scheduler::getSuspension();
    }

    /**
     * End the wait: something happened that the waiting call must check.
     *
     * @return bool Whether this call ended the wait; false when it had already ended.
     */
    public function wake(): bool
    {
        if ($this->settled) {
            return false;
        }

        $this->settled = true;
        if ($this->suspended) {
            $this->suspension->resume();
        }

        return true;
    }

    /**
     * End the wait with the given failure.
     *
     * @return bool Whether this call ended the wait; false when it had already ended.
     */
    public function fail(Throwable $failure): bool
    {
        if ($this->settled) {
            return false;
        }

        $this->settled = true;
        if ($this->suspended) {
            $this->suspension->throw($failure);
        } else {
            $this->failure = $failure;
        }

        return true;
    }

    /**
     * Suspend until the wait is settled, unless it already is.
     *
     * @throws Throwable The failure that settled the wait, see {@see fail()}.
     */
    public function wait(): void
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
