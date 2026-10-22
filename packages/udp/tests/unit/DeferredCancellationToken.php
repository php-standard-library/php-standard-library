<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

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
    /**
     * Always true: the token can be cancelled.
     */
    public readonly bool $cancellable;

    /**
     * The exception the token cancels with.
     */
    public readonly CancelledException $exception;

    /**
     * Whether the token has been cancelled.
     */
    private bool $cancelled = false;

    /**
     * The listeners subscribed and not yet notified, by subscription identifier.
     *
     * @var array<non-empty-string, (Closure(CancelledException): void)>
     */
    private array $callbacks = [];

    /**
     * The listeners `cancel()` took, which `deliver()` notifies.
     *
     * @var list<(Closure(CancelledException): void)>
     */
    private array $snapshot = [];

    /**
     * The number of the last subscription, for its identifier.
     */
    private int $next = 0;

    /**
     * Create a token that is not cancelled yet.
     */
    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    /**
     * Cancel the token, and take a snapshot of its listeners without notifying them yet.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
        foreach ($this->callbacks as $callback) {
            $this->snapshot[] = $callback;
        }

        $this->callbacks = [];
    }

    /**
     * Notify the listeners of the snapshot `cancel()` took.
     */
    public function deliver(): void
    {
        $snapshot = $this->snapshot;
        $this->snapshot = [];
        foreach ($snapshot as $callback) {
            $callback($this->exception);
        }
    }

    /**
     * Keep the listener until the token is cancelled, or notify it at once if it already is.
     */
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

    /**
     * Forget the listener, unless `cancel()` has already taken it.
     */
    #[Override]
    public function unsubscribe(string $id): void
    {
        unset($this->callbacks[$id]);
    }

    /**
     * Report whether the token has been cancelled.
     */
    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Throw the token's exception once it is cancelled.
     */
    #[Override]
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw $this->exception;
        }
    }
}
