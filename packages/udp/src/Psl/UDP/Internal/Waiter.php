<?php

declare(strict_types=1);

namespace Psl\UDP\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * One wait of a UDP socket for readiness: a receive or a peek waiting for a datagram, or a send waiting until the
 * socket can take one.
 *
 * Whatever ends the wait first settles it, the socket becoming ready, a cancellation or the socket closing, and
 * everything after that is ignored. Each wait has its own record because the event loop hands the same suspension to
 * every wait of a fiber: a cancellation listener that reached the suspension directly could end a later wait of the
 * same fiber. A wait that settles before it has suspended keeps its failure and throws it without suspending: a
 * suspension cannot be resumed before it has suspended.
 *
 * @internal
 */
final class Waiter
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
        $this->suspension = EventLoop::getSuspension();
    }

    /**
     * End the wait: the socket is ready.
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
