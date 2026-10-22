<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Exception as RootException;
use Throwable;

/**
 * A cancellation token that can be manually triggered.
 *
 * Call {@see cancel()} to cancel all subscribed operations.
 *
 * @api
 */
final class SignalCancellationToken implements CancellationTokenInterface
{
    public readonly bool $cancellable;

    private bool $cancelled = false;

    private null|Exception\CancelledException $exception = null;

    /**
     * @var array<string, (Closure(Exception\CancelledException): void)>
     */
    private array $callbacks = [];

    public function __construct()
    {
        $this->cancellable = true;
    }

    /**
     * Cancel all subscribed operations.
     *
     * Every subscribed callback runs, in subscription order, even if an earlier one throws. Once all of them
     * ran, the failure of the only callback that threw is rethrown unchanged, or the failures of several are
     * rethrown in a {@see Exception\CompositeException}. The token is cancelled either way.
     *
     * @param null|RootException $cause Optional cause exception, attached as the previous exception on the {@see Exception\CancelledException}.
     *
     * @throws Throwable The failure of the only subscribed callback that threw, unchanged.
     * @throws Exception\CompositeException The failures of the subscribed callbacks that threw, if several did.
     */
    public function cancel(null|RootException $cause = null): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->exception = new Exception\CancelledException($this, $cause);

        $exception = $this->exception;
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        Internal\notify_cancellation($callbacks, $exception);
    }

    /**
     * @inheritDoc
     */
    public function subscribe(Closure $callback): string
    {
        if ($this->cancelled) {
            /** @var Exception\CancelledException $exception - always set when cancelled */
            $exception = $this->exception;
            $callback($exception);

            return 'null';
        }

        $id = Internal\next_id();
        $this->callbacks[$id] = $callback;

        return $id;
    }

    /**
     * @inheritDoc
     */
    public function unsubscribe(string $id): void
    {
        unset($this->callbacks[$id]);
    }

    /**
     * @inheritDoc
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * @inheritDoc
     */
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            /** @var Exception\CancelledException $exception */
            $exception = $this->exception;

            throw $exception;
        }
    }
}
