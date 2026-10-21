<?php

declare(strict_types=1);

namespace Psl\Channel\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Channel\Exception;
use Psl\Channel\ReceiverInterface;
use Revolt\EventLoop;

/**
 * @template T
 *
 * @implements ReceiverInterface<T>
 *
 * @internal
 */
final class UnboundedReceiver implements ReceiverInterface
{
    /**
     * @use ChannelSideTrait<UnboundedChannelState<T>>
     */
    use ChannelSideTrait;

    private null|Waiter $waiter = null;

    /**
     * @param UnboundedChannelState<T> $state
     */
    public function __construct(UnboundedChannelState $state)
    {
        $this->state = $state;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function receive(CancellationTokenInterface $cancellation = new NullCancellationToken()): mixed
    {
        $waiter = null;

        try {
            // If another receive() is already waiting on this receiver, queue behind it instead of
            // taking the message it may have been woken up for. A closed channel wakes no one up
            // anymore, so never queue on it.
            if (null !== $this->waiter && !$this->state->isClosed()) {
                $waiter = new Waiter(EventLoop::getSuspension());
                $this->waitForMessage($waiter, $cancellation);
            }

            // Being woken up does not guarantee a message: another receiver may have taken it
            // before this fiber got to run, in which case we wait again.
            while ($this->state->isEmpty() && !$this->state->isClosed()) {
                $waiter ??= new Waiter(EventLoop::getSuspension());
                $this->waitForMessage($waiter, $cancellation);
            }

            return $this->state->receive();
        } finally {
            $this->waiter = null;
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function tryReceive(): mixed
    {
        return $this->state->receive();
    }

    /**
     * Wait until this receiver is woken up by a sent message.
     *
     * @throws Exception\ClosedChannelException If the channel is closed while waiting.
     * @throws CancelledException If the cancellation token is cancelled while waiting.
     */
    private function waitForMessage(Waiter $waiter, CancellationTokenInterface $cancellation): void
    {
        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $this->waiter = $waiter;
        $this->state->waitForMessage($waiter);

        $id = null;

        try {
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($waiter): void {
                    // The waiter may have already been woken up by a send, or failed by close().
                    if (!$this->state->removeFromWaitingForMessage($waiter)) {
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
            $this->state->removeFromWaitingForMessage($waiter);
        }
    }
}
