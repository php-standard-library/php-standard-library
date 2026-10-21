<?php

declare(strict_types=1);

namespace Psl\Channel\Tests\Unit;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;

use function count;

/**
 * A test token that runs a hook from within {@see subscribe()}, once the callback is registered.
 *
 * It stands in for a custom token that cancels while an operation subscribes to it, or runs code
 * that sends, receives or closes the channel at that point: after the operation registered itself
 * as waiting, and before it suspended.
 */
final class SubscribeHookToken implements CancellationTokenInterface
{
    public bool $cancellable = true;

    private null|CancelledException $exception = null;

    /**
     * @var array<non-empty-string, (Closure(CancelledException): void)>
     */
    private array $callbacks = [];

    /**
     * @var array<non-empty-string, true>
     */
    private array $subscriptions = [];

    private int $lastId = 0;

    /**
     * @param (Closure(SubscribeHookToken): void) $hook
     */
    public function __construct(
        private readonly Closure $hook,
    ) {}

    public function cancel(): void
    {
        if (null !== $this->exception) {
            return;
        }

        $exception = new CancelledException($this);
        $this->exception = $exception;

        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback($exception);
        }
    }

    /**
     * Returns the number of subscriptions that were not unsubscribed yet.
     */
    public function countSubscriptions(): int
    {
        return count($this->subscriptions);
    }

    #[Override]
    public function subscribe(Closure $callback): string
    {
        $this->lastId++;
        $id = 'subscription-' . $this->lastId;
        $this->subscriptions[$id] = true;

        if (null !== $this->exception) {
            $callback($this->exception);

            return $id;
        }

        $this->callbacks[$id] = $callback;

        ($this->hook)($this);

        return $id;
    }

    #[Override]
    public function unsubscribe(string $id): void
    {
        unset($this->callbacks[$id], $this->subscriptions[$id]);
    }

    #[Override]
    public function isCancelled(): bool
    {
        return null !== $this->exception;
    }

    #[Override]
    public function throwIfCancelled(): void
    {
        if (null !== $this->exception) {
            throw $this->exception;
        }
    }
}
