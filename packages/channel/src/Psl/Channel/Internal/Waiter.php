<?php

declare(strict_types=1);

namespace Psl\Channel\Internal;

use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * A blocked channel call waiting to be woken up, failed or cancelled.
 *
 * The channel settles a waiter once per wait, and only resumes its suspension once the call has
 * actually suspended. A waiter settled before that, e.g. by a token that cancels while the call
 * subscribes to it, keeps its outcome for the call to pick up instead of suspending.
 *
 * @internal
 */
final class Waiter
{
    /**
     * Whether the waiter is in a channel's wait list.
     *
     * The channel state sets it when adding the waiter to a wait list; settling the waiter, which
     * the channel only does once it took the waiter out of its wait list, clears it.
     */
    public bool $waiting = false;

    private bool $suspended = false;

    private bool $settled = false;

    private null|Throwable $failure = null;

    /**
     * @param Suspension<mixed> $suspension
     */
    public function __construct(
        private readonly Suspension $suspension,
    ) {}

    /**
     * Wake the call up, so it checks the channel again.
     */
    public function wake(): void
    {
        $this->settle(null);
    }

    /**
     * Fail the call with the given exception.
     */
    public function fail(Throwable $failure): void
    {
        $this->settle($failure);
    }

    /**
     * Wait until the waiter is settled, unless it already is, then make it ready to wait again.
     *
     * @throws Throwable The exception the waiter was failed with.
     */
    public function wait(): void
    {
        if (!$this->settled) {
            $this->suspended = true;

            try {
                $this->suspension->suspend();
            } finally {
                $this->suspended = false;
            }
        }

        $failure = $this->failure;
        $this->settled = false;
        $this->failure = null;

        if (null !== $failure) {
            throw $failure;
        }
    }

    private function settle(null|Throwable $failure): void
    {
        $this->waiting = false;
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->failure = $failure;

        if ($this->suspended) {
            $this->suspension->resume(null);
        }
    }
}
