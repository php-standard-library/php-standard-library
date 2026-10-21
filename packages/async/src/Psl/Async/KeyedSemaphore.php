<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Exception;
use Fiber;
use Psl\Async\Exception\CancelledException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function array_key_exists;
use function array_search;
use function array_shift;
use function array_splice;
use function array_sum;
use function count;
use function spl_object_id;

/**
 * Run an operation with a limit on number of ongoing asynchronous jobs for a specific key.
 *
 * All operations must have the same input type (Tin) and output type (Tout), and be processed by the same function.
 *
 * `Tin` may be a callable invoked by the `$operation` for maximum flexibility,
 * however this pattern is best avoided in favor of creating semaphores with a more narrow process.
 *
 * @template-contravariant Tk of array-key
 * @template-contravariant Tin
 * @template-covariant Tout
 *
 * @mago-expect lint:excessive-nesting
 *
 * @api
 */
final class KeyedSemaphore
{
    /**
     * The number of slots taken for each key: one for each execution context running an operation,
     * and one for each operation that a slot was handed over to, but that has not resumed yet.
     *
     * @var array<Tk, int<0, max>>
     */
    private array $ongoing = [];

    /**
     * The execution contexts (fibers, or this semaphore for {main}) holding a slot for each key, by object id.
     *
     * A context calling {@see waitFor()} again for a key it holds a slot for re-enters: the re-entrant call
     * runs right away using that slot, and only the outermost call releases it.
     *
     * @var array<Tk, array<int, Fiber|self>>
     */
    private array $holders = [];

    /**
     * @var array<Tk, list<Suspension>>
     */
    private array $pending = [];

    /**
     * @var array<Tk, list<Suspension>>
     */
    private array $waits = [];

    /**
     * @param positive-int $concurrencyLimit
     * @param (Closure(Tk, Tin): Tout) $operation
     */
    public function __construct(
        private readonly int $concurrencyLimit,
        private readonly Closure $operation,
    ) {}

    /**
     * Run the operation using the given `$input`.
     *
     * If the concurrency limit has been reached for the given `$key`, this method will wait until one of the ongoing operations has completed.
     *
     * If the current fiber (or {main}) is already running an operation for the given `$key`, the operation
     * runs right away, using the slot the outer operation holds.
     *
     * @param Tk $key
     * @param Tin $input
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     *
     * @return Tout
     *
     * @see Semaphore::cancel()
     *
     * @suspens-fiber
     */
    public function waitFor(
        string|int $key,
        mixed $input,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): mixed {
        $currentContext = Fiber::getCurrent() ?? $this;
        $contextId = spl_object_id($currentContext);
        if (isset($this->holders[$key][$contextId])) {
            // A re-entrant call runs using the slot its context already holds, which the outermost call releases.
            return $this->invoke($key, $input);
        }

        $this->ongoing[$key] ??= 0;
        if ($this->ongoing[$key] >= $this->concurrencyLimit) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->pending[$key][] = $suspension;

            $id = null;
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($key, $suspension): void {
                    $index = array_search($suspension, $this->pending[$key] ?? [], true);
                    if (false !== $index) {
                        array_splice($this->pending[$key], $index, 1);
                        if ([] === $this->pending[$key]) {
                            unset($this->pending[$key]);
                        }

                        $suspension->throw($e);
                    }
                });
            }

            try {
                // Once resumed, the slot of the operation that completed has been handed over to us,
                // and is already accounted for in `$this->ongoing[$key]`.
                $suspension->suspend();
            } finally {
                if (null !== $id) {
                    $cancellation->unsubscribe($id);
                }
            }
        } else {
            $this->ongoing[$key]++;
        }

        $this->holders[$key][$contextId] = $currentContext;

        try {
            return $this->invoke($key, $input);
        } finally {
            unset($this->holders[$key][$contextId]);

            $this->release($key);
        }
    }

    /**
     * Cancel pending operations for the given key.
     *
     * Pending operation will fail with the given exception.
     *
     * Future operations will continue execution as usual.
     *
     * @param Tk $key
     */
    public function cancel(string|int $key, Exception $exception): void
    {
        $suspensions = $this->pending[$key] ?? [];
        unset($this->pending[$key]);
        foreach ($suspensions as $suspension) {
            $suspension->throw($exception);
        }
    }

    /**
     * Cancel all pending operations.
     *
     * Pending operation will fail with the given exception.
     *
     * Future operations will continue execution as usual.
     */
    public function cancelAll(Exception $exception): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $suspensions) {
            foreach ($suspensions as $suspension) {
                $suspension->throw($exception);
            }
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
     * Get the number of operations pending execution for the given key.
     *
     * @param Tk $key
     *
     * @return int<0, max>
     */
    public function getPendingOperations(string|int $key): int
    {
        return count($this->pending[$key] ?? []);
    }

    /**
     * Get the number of total operations pending execution.
     *
     * @return int<0, max>
     */
    public function getTotalPendingOperations(): int
    {
        $count = 0;
        foreach ($this->pending as $suspensions) {
            $count += count($suspensions);
        }

        return $count;
    }

    /**
     * Check if there's any operations pending execution for the given key.
     *
     * If this method returns `true`, it means the semaphore has reached it's limits, future calls to `waitFor` will wait.
     *
     * @param Tk $key
     */
    public function hasPendingOperations(string|int $key): bool
    {
        return array_key_exists($key, $this->pending);
    }

    /**
     * Check if there's any operations pending execution.
     */
    public function hasAnyPendingOperations(): bool
    {
        return [] !== $this->pending;
    }

    /**
     * Get the number of ongoing operations for the given key.
     *
     * Each fiber (or {main}) running an operation for the given key counts once, however many times it
     * re-entered {@see waitFor()} for that key, so the returned number never exceeds the concurrency limit.
     * An operation that the slot of a completed operation was handed over to counts as ongoing once the slot
     * is handed over, even if it has not resumed yet.
     *
     * @param Tk $key
     *
     * @return int<0, max>
     */
    public function getOngoingOperations(string|int $key): int
    {
        return $this->ongoing[$key] ?? 0;
    }

    /**
     * Get the number of total ongoing operations.
     *
     * The returned number can be higher than the concurrency limit, as it is the sum of all ongoing operations using different keys.
     *
     * @return int<0, max>
     */
    public function getTotalOngoingOperations(): int
    {
        /** @var int<0, max> */
        return array_sum($this->ongoing);
    }

    /**
     * Check if the semaphore has any ongoing operations for the given key.
     *
     * If this method returns `true`, it does not mean future calls to `waitFor` will wait, since a semaphore can have multiple ongoing operations
     * at the same time for the same key.
     *
     * @param Tk $key
     */
    public function hasOngoingOperations(string|int $key): bool
    {
        return array_key_exists($key, $this->ongoing);
    }

    /**
     * Check if the semaphore has any ongoing operations.
     */
    public function hasAnyOngoingOperations(): bool
    {
        return [] !== $this->ongoing;
    }

    /**
     * Wait for all pending operations associated with the given key to start execution.
     *
     * If the semaphore is has not reached the concurrency limit the given key, this method will return immediately.
     *
     * @param Tk $key
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    public function waitForPending(
        string|int $key,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        if (($this->ongoing[$key] ?? 0) < $this->concurrencyLimit) {
            return;
        }

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $this->waits[$key][] = $suspension;

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(function (CancelledException $e) use ($key, $suspension): void {
                $index = array_search($suspension, $this->waits[$key] ?? [], true);
                if (false !== $index) {
                    array_splice($this->waits[$key], $index, 1);
                    if ([] === $this->waits[$key]) {
                        unset($this->waits[$key]);
                    }

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

    /**
     * Release the slot of a completing outermost operation for the given key.
     *
     * The slot is handed over to the next pending operation, if any, without being released: the resumed
     * operation runs later, and releasing the slot now would let a new caller take it in between. Otherwise,
     * the slot is released, and the {@see waitForPending()} calls for the given key are resumed.
     *
     * @param Tk $key
     */
    private function release(string|int $key): void
    {
        if (($this->pending[$key] ?? []) !== []) {
            $suspension = array_shift($this->pending[$key]);
            if ([] === $this->pending[$key]) {
                unset($this->pending[$key]);
            }

            if (null !== $suspension) {
                $suspension->resume();

                return;
            }
        }

        foreach ($this->waits[$key] ?? [] as $suspension) {
            $suspension->resume();
        }

        unset($this->waits[$key]);

        // @mago-expect analysis:invalid-property-assignment-value - true positive, no one will modify this.
        $this->ongoing[$key]--;
        if (0 === $this->ongoing[$key]) {
            unset($this->ongoing[$key], $this->holders[$key]);
        }
    }

    /**
     * @param Tk $key
     * @param Tin $input
     *
     * @return Tout
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     *
     * @suspens-fiber
     */
    private function invoke(string|int $key, mixed $input): mixed
    {
        return ($this->operation)($key, $input);
    }
}
