<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Exception;
use Psl\Async\Exception\CancelledException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function array_search;
use function array_shift;
use function array_splice;
use function count;

/**
 * Run an operation with a limit on number of ongoing asynchronous jobs.
 *
 * All operations must have the same input type (Tin) and output type (Tout), and be processed by the same function;
 * `Tin` may be a callable invoked by the `$operation` for maximum flexibility,
 * however this pattern is best avoided in favor of creating semaphores with a more narrow process.
 *
 * @template-contravariant Tin
 * @template-covariant Tout
 *
 * @api
 */
final class Semaphore
{
    /**
     * @var int<0, max>
     */
    private int $ongoing = 0;

    /**
     * @var list<Suspension>
     */
    private array $pending = [];

    /**
     * @var list<Suspension>
     */
    private array $waits = [];

    /**
     * @param positive-int $concurrencyLimit
     * @param (Closure(Tin): Tout) $operation
     */
    public function __construct(
        private readonly int $concurrencyLimit,
        private readonly Closure $operation,
    ) {}

    /**
     * Run the operation using the given `$input`.
     *
     * If the concurrency limit has been reached, this method will wait until one of the ongoing operations has completed.
     *
     * @param Tin $input
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     *
     * @return Tout
     *
     * @see Semaphore::cancel()
     */
    public function waitFor(mixed $input, CancellationTokenInterface $cancellation = new NullCancellationToken()): mixed
    {
        if ($this->ongoing < $this->concurrencyLimit) {
            $this->ongoing++;
        } else {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->pending[] = $suspension;

            $id = null;
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                    $index = array_search($suspension, $this->pending, true);
                    if (false !== $index) {
                        array_splice($this->pending, $index, 1);
                        $suspension->throw($e);
                    }
                });
            }

            try {
                // Once resumed, the slot of the operation that completed has been handed over to us,
                // and is already accounted for in `$this->ongoing`.
                $suspension->suspend();
            } finally {
                if (null !== $id) {
                    $cancellation->unsubscribe($id);
                }
            }
        }

        try {
            return ($this->operation)($input);
        } finally {
            $suspension = array_shift($this->pending);
            if (null !== $suspension) {
                // Hand the slot over to the next pending operation without releasing it; the resumed
                // operation runs later, and releasing the slot now would let a new caller take it in between.
                $suspension->resume();
            } else {
                foreach ($this->waits as $suspension) {
                    $suspension->resume();
                }

                $this->waits = [];

                // @mago-expect analysis:invalid-property-assignment-value - this operation holds a slot, so the count is positive.
                $this->ongoing--;
            }
        }
    }

    /**
     * Cancel all pending operations.
     *
     * Any pending operation will fail with the given exception.
     *
     * Future operations will continue execution as usual.
     */
    public function cancel(Exception $exception): void
    {
        $suspensions = $this->pending;
        $this->pending = [];
        foreach ($suspensions as $suspension) {
            $suspension->throw($exception);
        }
    }

    /**
     * Get the concurrency limit of the semaphore.
     *
     * @return positive-int
     */
    public function getConcurrencyLimit(): int
    {
        return $this->concurrencyLimit;
    }

    /**
     * Get the number of operations pending execution.
     *
     * @return int<0, max>
     */
    public function getPendingOperations(): int
    {
        return count($this->pending);
    }

    /**
     * Check if there's any operations pending execution.
     *
     * If this method returns `true`, it means the semaphore is full, future calls to `waitFor` will wait.
     */
    public function hasPendingOperations(): bool
    {
        return [] !== $this->pending;
    }

    /**
     * Get the number of ongoing operations.
     *
     * The returned number will always be lower, or equal to the concurrency limit.
     *
     * @return int<0, max>
     */
    public function getOngoingOperations(): int
    {
        return $this->ongoing;
    }

    /**
     * Check if the semaphore has any ongoing operations.
     *
     * If this method returns `true`, it does not mean future calls to `waitFor` will wait, since a semaphore can have multiple ongoing operations
     * at the same time.
     */
    public function hasOngoingOperations(): bool
    {
        return $this->ongoing > 0;
    }

    /**
     * Wait for all pending operations to finish execution.
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    public function waitForPending(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        if ($this->ongoing !== $this->concurrencyLimit) {
            return;
        }

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $this->waits[] = $suspension;

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                $index = array_search($suspension, $this->waits, true);
                if (false !== $index) {
                    array_splice($this->waits, $index, 1);
                    $suspension->throw($e);
                }
            });
        }

        try {
            $suspension->suspend();
        } finally {
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }
        }
    }
}
