<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;

/**
 * A token whose notification runs some time after it is cancelled.
 *
 * `cancel()` takes a snapshot of the listeners and clears them; `deliver()` notifies that snapshot later. A listener
 * that unsubscribes in between is still notified: unsubscribing cannot take a listener out of a snapshot that has
 * already been taken.
 */
final class DeferredCancellationToken implements CancellationTokenInterface
{
    public readonly bool $cancellable;

    public readonly CancelledException $exception;

    private bool $cancelled = false;

    /**
     * @var array<non-empty-string, (Closure(CancelledException): void)>
     */
    private array $callbacks = [];

    /**
     * @var list<(Closure(CancelledException): void)>
     */
    private array $snapshot = [];

    private int $next = 0;

    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        foreach ($this->callbacks as $callback) {
            $this->snapshot[] = $callback;
        }

        $this->callbacks = [];
    }

    public function deliver(): void
    {
        $snapshot = $this->snapshot;
        $this->snapshot = [];
        foreach ($snapshot as $callback) {
            $callback($this->exception);
        }
    }

    #[Override]
    public function subscribe(Closure $callback): string
    {
        if ($this->cancelled) {
            $callback($this->exception);

            return 'cancelled';
        }

        $id = 'listener-' . ++$this->next;
        $this->callbacks[$id] = $callback;

        return $id;
    }

    #[Override]
    public function unsubscribe(string $id): void
    {
        unset($this->callbacks[$id]);
    }

    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    #[Override]
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw $this->exception;
        }
    }
}
