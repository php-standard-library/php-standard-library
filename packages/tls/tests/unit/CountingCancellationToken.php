<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;

/**
 * A token that delegates to another and counts how many listeners subscribed to it.
 *
 * Every wait that may be cancelled subscribes once, so the count is the number of waits.
 */
final class CountingCancellationToken implements CancellationTokenInterface
{
    /**
     * Whether the wrapped token can be cancelled.
     */
    public readonly bool $cancellable;

    /**
     * How many listeners have subscribed so far.
     */
    public private(set) int $subscriptions = 0;

    /**
     * Wrap the given token.
     */
    public function __construct(
        /**
         * The token that decides when this one is cancelled.
         */
        private readonly CancellationTokenInterface $inner,
    ) {
        $this->cancellable = $inner->cancellable;
    }

    /**
     * Count the listener, and subscribe it to the wrapped token.
     */
    #[Override]
    public function subscribe(Closure $callback): string
    {
        $this->subscriptions++;

        return $this->inner->subscribe($callback);
    }

    /**
     * Unsubscribe the listener from the wrapped token.
     */
    #[Override]
    public function unsubscribe(string $id): void
    {
        $this->inner->unsubscribe($id);
    }

    /**
     * Report whether the wrapped token is cancelled.
     */
    #[Override]
    public function isCancelled(): bool
    {
        return $this->inner->isCancelled();
    }

    /**
     * Throw the wrapped token's exception once it is cancelled.
     */
    #[Override]
    public function throwIfCancelled(): void
    {
        $this->inner->throwIfCancelled();
    }
}
