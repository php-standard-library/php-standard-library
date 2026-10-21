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
use function count;

/**
 * Run an operation with a limit on number of ongoing asynchronous jobs of 1.
 *
 * Just like {@see KeyedSemaphore}, all operations must have the same input type (Tin) and output type (Tout), and be processed by the same function;
 *
 * @template-contravariant Tk of array-key
 * @template-contravariant Tin
 * @template-covariant Tout
 *
 * @see KeyedSemaphore
 *
 * @mago-expect lint:excessive-nesting
 *
 * @api
 */
final class KeyedSequence
{
    /**
     * The execution context (fiber, or this sequence for {main}) holding each key.
     *
     * A context calling {@see waitFor()} again for a key it holds re-enters: the re-entrant call runs
     * right away, and only the outermost call releases the key. A completing operation hands the key
     * over to the next pending operation's context before resuming it.
     *
     * @var array<Tk, Fiber|self>
     */
    private array $ongoing = [];

    /**
     * The pending operations for each key: their suspension, and the context waiting on it.
     *
     * @var array<Tk, list<array{Suspension, Fiber|self}>>
     */
    private array $pending = [];

    /**
     * @var array<Tk, list<Suspension>>
     */
    private array $waits = [];

    /**
     * @param (Closure(Tk, Tin): Tout) $operation
     */
    public function __construct(
        private readonly Closure $operation,
    ) {}

    /**
     * Run the operation using the given `$input`, after all previous operations have completed.
     *
     * @param Tk $key
     * @param Tin $input
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     *
     * @return Tout
     *
     * @see Sequence::cancel()
     */
    public function waitFor(
        string|int $key,
        mixed $input,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): mixed {
        $currentContext = Fiber::getCurrent() ?? $this;
        if (isset($this->ongoing[$key])) {
            if ($this->ongoing[$key] === $currentContext) {
                return ($this->operation)($key, $input);
            }

            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $waiter = [$suspension, $currentContext];
            $this->pending[$key][] = $waiter;

            $id = null;
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($key, $waiter): void {
                    $index = array_search($waiter, $this->pending[$key] ?? [], true);
                    if (false !== $index) {
                        array_splice($this->pending[$key], $index, 1);
                        if ([] === $this->pending[$key]) {
                            unset($this->pending[$key]);
                        }

                        $waiter[0]->throw($e);
                    }
                });
            }

            try {
                // Once resumed, the key has been handed over to us: `$this->ongoing[$key]` is already our context.
                $suspension->suspend();
            } finally {
                if (null !== $id) {
                    $cancellation->unsubscribe($id);
                }
            }
        } else {
            $this->ongoing[$key] = $currentContext;
        }

        try {
            return ($this->operation)($key, $input);
        } finally {
            $next = null;
            if (isset($this->pending[$key])) {
                $next = array_shift($this->pending[$key]);
                if ([] === $this->pending[$key]) {
                    unset($this->pending[$key]);
                }
            }

            if (null !== $next) {
                // Hand the key over before resuming the next operation: it runs later, and the completing
                // context must not be able to re-enter in between.
                $this->ongoing[$key] = $next[1];
                $next[0]->resume();
            } else {
                foreach ($this->waits[$key] ?? [] as $suspension) {
                    $suspension->resume();
                }

                unset($this->waits[$key], $this->ongoing[$key]);
            }
        }
    }

    /**
     * Cancel pending operations for the given key.
     *
     * Any pending operation will fail with the given exception.
     *
     * Future operations will continue execution as usual.
     *
     * @param Tk $key
     */
    public function cancel(string|int $key, Exception $exception): void
    {
        $operations = $this->pending[$key] ?? [];
        unset($this->pending[$key]);
        foreach ($operations as [$suspension]) {
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
        foreach ($pending as $operations) {
            foreach ($operations as [$suspension]) {
                $suspension->throw($exception);
            }
        }
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
        foreach ($this->pending as $operations) {
            $count += count($operations);
        }

        return $count;
    }

    /**
     * Check if there's any operations pending execution for the given key.
     *
     * If this method returns `true`, it means the sequence is busy, future calls to `waitFor` will wait.
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
     * Check if there's an ongoing operation for the given key.
     *
     * If this method returns `true`, it means the sequence is busy, future calls to `waitFor` will wait.
     * If this method returns `false`, it means the sequence is not busy, future calls to `waitFor` will execute immediately.
     *
     * @param Tk $key
     */
    public function hasOngoingOperations(string|int $key): bool
    {
        return array_key_exists($key, $this->ongoing);
    }

    /**
     * Check if the sequence has any ongoing operations.
     */
    public function hasAnyOngoingOperations(): bool
    {
        return [] !== $this->ongoing;
    }

    /**
     * Get the number of total ongoing operations.
     *
     * @return int<0, max>
     */
    public function getTotalOngoingOperations(): int
    {
        return count($this->ongoing);
    }

    /**
     * Wait for all pending operations associated with the given key to finish execution.
     *
     * If the sequence does not have any ongoing operations for the given key, this method will return immediately.
     *
     * @param Tk $key
     *
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    public function waitForPending(
        string|int $key,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        if (!array_key_exists($key, $this->ongoing)) {
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
}
