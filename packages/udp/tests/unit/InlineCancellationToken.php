<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;

/**
 * A token that is cancelled by the act of subscribing, as by a hook that runs during `subscribe()`, and that
 * notifies the new listener inline, from within `subscribe()`.
 */
final class InlineCancellationToken implements CancellationTokenInterface
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
     * Whether anything has subscribed, which cancels the token.
     */
    private bool $cancelled = false;

    /**
     * Create a token that nothing has subscribed to yet.
     */
    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    /**
     * Cancel the token, and notify the new listener before returning.
     */
    #[Override]
    public function subscribe(Closure $callback): string
    {
        $this->cancelled = true;
        $callback($this->exception);

        return 'inline';
    }

    /**
     * Do nothing: no listener is ever kept.
     */
    #[Override]
    public function unsubscribe(string $id): void {}

    /**
     * Report whether anything has subscribed.
     */
    #[Override]
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Throw the token's exception once anything has subscribed.
     */
    #[Override]
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw $this->exception;
        }
    }
}
