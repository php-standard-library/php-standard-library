<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Psl\DateTime\Duration;
use Revolt\EventLoop;
use WeakReference;

use function max;

/**
 * A cancellation token that automatically cancels after a given duration.
 *
 * When the token cancels, every subscribed callback runs, in subscription order, even if an earlier one
 * throws. The failure of the only callback that threw, or a {@see Exception\CompositeException} of the
 * failures of several, then reaches the event loop error handler.
 *
 * @api
 */
final class TimeoutCancellationToken implements CancellationTokenInterface
{
    public readonly bool $cancellable;

    private bool $cancelled = false;

    private null|Exception\CancelledException $exception = null;

    /**
     * @var array<string, (Closure(Exception\CancelledException): void)>
     */
    private array $callbacks = [];

    private readonly string $watcher;

    public function __construct(Duration $timeout)
    {
        $this->cancellable = true;

        $self = WeakReference::create($this);

        $this->watcher = EventLoop::delay(max($timeout->getTotalSeconds(), 0.0), static function () use ($self): void {
            $token = $self->get();
            if (null === $token) {
                return;
            }

            $token->cancelled = true;
            $token->exception = new Exception\CancelledException($token, new Exception\TimeoutException());

            $exception = $token->exception;
            $callbacks = $token->callbacks;
            $token->callbacks = [];

            // The failures of the callbacks go to the event loop error handler.
            Internal\notify_cancellation($callbacks, $exception);
        });

        EventLoop::unreference($this->watcher);
    }

    /**
     * @inheritDoc
     */
    public function subscribe(Closure $callback): string
    {
        if ($this->cancelled) {
            /** @var Exception\CancelledException $exception */
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

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }
}
