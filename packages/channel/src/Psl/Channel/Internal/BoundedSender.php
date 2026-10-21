<?php

declare(strict_types=1);

namespace Psl\Channel\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Channel\Exception;
use Psl\Channel\SenderInterface;
use Revolt\EventLoop;

/**
 * @template T
 *
 * @implements SenderInterface<T>
 *
 * @internal
 */
final class BoundedSender implements SenderInterface
{
    /**
     * @use ChannelSideTrait<BoundedChannelState<T>>
     */
    use ChannelSideTrait;

    private null|Waiter $waiter = null;

    /**
     * @param BoundedChannelState<T> $state
     */
    public function __construct(BoundedChannelState $state)
    {
        $this->state = $state;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function send(mixed $message, CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        $waiter = null;

        try {
            // If another send() is already waiting on this sender, queue behind it instead of
            // taking the space it may have been woken up for. A closed channel wakes no one up
            // anymore, so never queue on it.
            if (null !== $this->waiter && !$this->state->isClosed()) {
                $waiter = new Waiter(EventLoop::getSuspension());
                $this->waitForSpace($waiter, $cancellation);
            }

            // Being woken up does not guarantee space: another sender may have taken it
            // before this fiber got to run, in which case we wait again.
            while ($this->state->isFull() && !$this->state->isClosed()) {
                $waiter ??= new Waiter(EventLoop::getSuspension());
                $this->waitForSpace($waiter, $cancellation);
            }

            $this->state->send($message);
        } finally {
            $this->waiter = null;
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function trySend(mixed $message): void
    {
        $this->state->send($message);
    }

    /**
     * Wait until this sender is woken up by a received message.
     *
     * @throws Exception\ClosedChannelException If the channel is closed while waiting.
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    private function waitForSpace(Waiter $waiter, CancellationTokenInterface $cancellation): void
    {
        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $this->waiter = $waiter;
        $this->state->waitForSpace($waiter);

        $id = null;

        try {
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($waiter): void {
                    // The waiter may have already been woken up by a receive, or failed by close().
                    if (!$this->state->removeFromWaitingForSpace($waiter)) {
                        return;
                    }

                    // A cancelled waiter takes nothing, so later calls must not queue behind it.
                    if ($this->waiter === $waiter) {
                        $this->waiter = null;
                    }

                    // When the token cancels inside subscribe(), the waiter is failed before this
                    // call suspends; it then keeps the exception for wait() to throw.
                    $waiter->fail($e);
                });
            }

            $waiter->wait();
        } finally {
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }

            // A waiter is still waiting here if subscribing to the token failed; take it out, so
            // nothing wakes it up anymore.
            $this->state->removeFromWaitingForSpace($waiter);
        }
    }
}
