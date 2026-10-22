<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Exception as RootException;
use Revolt\EventLoop;

use function array_search;
use function array_splice;
use function count;

/**
 * A group of concurrent tasks that can be awaited together.
 *
 * Deferred closures run concurrently. {@see awaitAll()} blocks until every
 * task has completed. If one or more tasks throw, all exceptions are collected
 * and thrown as a {@see Exception\CompositeException} after every task finishes.
 *
 * @api
 */
final class TaskGroup
{
    /**
     * @var int<0, max>
     */
    private int $pending = 0;

    /**
     * The exceptions thrown by the tasks since a join last took them, in the order the tasks failed.
     *
     * @var list<RootException>
     */
    private array $exceptions = [];

    /**
     * The joins waiting for the running tasks; the last task to finish resumes each of them with the
     * outcome of the batch.
     *
     * @var list<EventLoop\Suspension>
     */
    private array $waiters = [];

    /**
     * Defer a closure for concurrent execution.
     *
     * The closure will start running on the next tick of the event loop.
     *
     * @param (Closure(): void) $closure
     */
    public function defer(Closure $closure): void
    {
        $this->pending++;

        EventLoop::defer(function () use ($closure): void {
            try {
                $closure();
            } catch (RootException $e) {
                $this->exceptions[] = $e;
            } finally {
                // @mago-expect analysis:invalid-property-assignment-value
                $this->pending--;

                if (0 === $this->pending && [] !== $this->waiters) {
                    // The joins waiting for this batch share its outcome, which is taken now: a task deferred
                    // before they resume belongs to the next batch.
                    $failure = $this->takeFailure();
                    $waiters = $this->waiters;
                    $this->waiters = [];

                    foreach ($waiters as $waiter) {
                        $waiter->resume($failure);
                    }
                }
            }
        });
    }

    /**
     * Wait for all deferred tasks to complete.
     *
     * If one task threw, its exception is thrown; if several tasks threw, a
     * {@see Exception\CompositeException} containing all the exceptions is thrown,
     * after every task has finished.
     *
     * Every call waiting when the last task finishes gets the same outcome: the
     * same exception, or the same composite. That outcome is then taken from the
     * group, so a later call returns unless new tasks fail. While no call waits,
     * exceptions stay in the group until the next call, including those of tasks
     * deferred after the group was idle.
     *
     * @throws Exception\CompositeException If multiple tasks failed.
     * @throws Exception\CancelledException If the cancellation token is cancelled.
     */
    public function awaitAll(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        if (0 !== $this->pending) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->waiters[] = $suspension;

            $id = null;
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (Exception\CancelledException $e) use ($suspension): void {
                    $index = array_search($suspension, $this->waiters, true);
                    if (false !== $index) {
                        array_splice($this->waiters, $index, 1);
                        $suspension->throw($e);
                    }
                });
            }

            try {
                /** @var null|RootException $failure */
                $failure = $suspension->suspend();
            } finally {
                if (null !== $id) {
                    $cancellation->unsubscribe($id);
                }
            }
        } else {
            $failure = $this->takeFailure();
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Take the exceptions collected so far, leaving the group with none.
     *
     * @return null|RootException Null if no task failed, the exception if one task failed, or a composite
     *                            of all the exceptions, in the order the tasks failed, if several did.
     */
    private function takeFailure(): null|RootException
    {
        $exceptions = $this->exceptions;
        $this->exceptions = [];

        if ([] === $exceptions) {
            return null;
        }

        if (1 === count($exceptions)) {
            return $exceptions[0];
        }

        return new Exception\CompositeException($exceptions);
    }
}
