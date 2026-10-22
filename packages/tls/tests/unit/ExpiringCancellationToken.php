<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;

/**
 * A deadline token whose deadline passes after the waiter's last check, and before it subscribes.
 *
 * Every check before `subscribe()` finds the token live, however many checks the waiter makes, so the waiter always
 * reaches `subscribe()`. There the deadline has passed: the token is cancelled from then on, and `subscribe()`, as for
 * any token that is already cancelled, invokes the new listener at once, before the waiter has suspended.
 */
final class ExpiringCancellationToken implements CancellationTokenInterface
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
     * Whether the deadline has passed, which happens when anything subscribes.
     */
    private bool $expired = false;

    /**
     * Create a token whose deadline has not passed yet.
     */
    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    /**
     * Let the deadline pass, and invoke the listener at once, as for a token that is already cancelled.
     */
    #[Override]
    public function subscribe(Closure $callback): string
    {
        $this->expired = true;
        $callback($this->exception);

        return 'expired';
    }

    /**
     * Do nothing: no listener is ever kept.
     */
    #[Override]
    public function unsubscribe(string $id): void {}

    /**
     * Report whether the deadline has passed: only once anything has subscribed.
     */
    #[Override]
    public function isCancelled(): bool
    {
        return $this->expired;
    }

    /**
     * Throw the token's exception once the deadline has passed.
     */
    #[Override]
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw $this->exception;
        }
    }
}
