<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use WeakReference;

/**
 * A cancellation token that is cancelled when either of two inner tokens is cancelled.
 *
 * When the token cancels, every subscribed callback runs, in subscription order, even if an earlier one
 * throws. The failure of the only callback that threw, or a {@see Exception\CompositeException} of the
 * failures of several, is then thrown to the inner token that cancelled, which reports it as the failure
 * of one of its own callbacks: {@see SignalCancellationToken::cancel()} rethrows it, and a
 * {@see TimeoutCancellationToken} sends it to the event loop error handler.
 *
 * This is useful for combining a request-scoped token with an operation-specific timeout:
 *
 *     $linked = new LinkedCancellationToken(
 *         $requestToken,
 *         new TimeoutCancellationToken(Duration::seconds(5)),
 *     );
 *
 * @api
 */
final class LinkedCancellationToken implements CancellationTokenInterface
{
    public readonly bool $cancellable;

    private bool $cancelled = false;

    private null|Exception\CancelledException $exception = null;

    /**
     * @var array<string, (Closure(Exception\CancelledException): void)>
     */
    private array $callbacks = [];

    /** @var non-empty-string */
    private readonly string $firstId;
    /** @var non-empty-string */
    private readonly string $secondId;

    /**
     * Link the token to its two inner tokens: it cancels, with the cancellation of the inner token,
     * when either of them cancels, and it is cancelled at once if one of them already is.
     */
    public function __construct(
        /**
         * The first inner token.
         */
        private readonly CancellationTokenInterface $first,

        /**
         * The second inner token.
         */
        private readonly CancellationTokenInterface $second,
    ) {
        $this->cancellable = $first->cancellable || $second->cancellable;

        $self = WeakReference::create($this);

        $handler = static function (Exception\CancelledException $inner) use ($self): void {
            $token = $self->get();
            if (null === $token || $token->cancelled) {
                return;
            }

            $token->cancelled = true;
            $token->exception = $inner;

            $callbacks = $token->callbacks;
            $token->callbacks = [];

            // The failures of the callbacks go to whatever notifies this handler: the inner token.
            Internal\notify_cancellation($callbacks, $inner);
        };

        $this->firstId = $first->subscribe($handler);
        $this->secondId = $second->subscribe($handler);
    }

    public function __destruct()
    {
        $this->first->unsubscribe($this->firstId);
        $this->second->unsubscribe($this->secondId);
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
}
