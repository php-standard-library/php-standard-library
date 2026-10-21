<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

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
    public readonly bool $cancellable;

    public readonly CancelledException $exception;

    private int $queries = 0;

    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    #[Override]
    public function subscribe(Closure $callback): string
    {
        $callback($this->exception);

        return 'expired';
    }

    #[Override]
    public function unsubscribe(string $id): void {}

    #[Override]
    public function isCancelled(): bool
    {
        $this->queries++;

        return $this->queries > 1;
    }

    #[Override]
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw $this->exception;
        }
    }
}
