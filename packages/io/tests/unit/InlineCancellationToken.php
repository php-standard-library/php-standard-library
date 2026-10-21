<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

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
    public readonly bool $cancellable;

    public readonly CancelledException $exception;

    private bool $cancelled = false;

    public function __construct()
    {
        $this->cancellable = true;
        $this->exception = new CancelledException($this);
    }

    #[Override]
    public function subscribe(Closure $callback): string
    {
        $this->cancelled = true;
        $callback($this->exception);

        return 'inline';
    }

    #[Override]
    public function unsubscribe(string $id): void {}

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
