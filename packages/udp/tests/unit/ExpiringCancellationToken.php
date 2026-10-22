<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;

/**
 * A deadline token whose deadline passes right after its first query.
 *
 * A waiter that checks the token before subscribing finds it expired only inside `subscribe()`, which, as for any
 * token that is already cancelled, invokes the new listener at once: before the waiter has suspended.
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
     * How many times the token has been asked whether it is cancelled.
     */
    private int $queries = 0;

    /**
     * Create a token whose deadline has not passed yet.
     */
    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    /**
     * Invoke the listener at once: the deadline has passed by the time anything subscribes.
     */
    #[Override]
    public function subscribe(Closure $callback): string
    {
        $callback($this->exception);

        return 'expired';
    }

    /**
     * Do nothing: no listener is ever kept.
     */
    #[Override]
    public function unsubscribe(string $id): void {}

    /**
     * Report the token cancelled from the second query on.
     */
    #[Override]
    public function isCancelled(): bool
    {
        $this->queries++;

        return $this->queries > 1;
    }

    /**
     * Throw the token's exception from the second query on.
     */
    #[Override]
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw $this->exception;
        }
    }
}
