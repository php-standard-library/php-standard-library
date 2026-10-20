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
use Revolt\EventLoop\Suspension;

/**
 * @template T
 *
 * @implements ReceiverInterface<T>
 *
 * @internal
 */
final class BoundedReceiver implements ReceiverInterface
{
    /**
     * @use ChannelSideTrait<BoundedChannelState<T>>
     */
    use ChannelSideTrait;

    private null|Suspension $suspension = null;

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
    public function receive(CancellationTokenInterface $cancellation = new NullCancellationToken()): mixed
    {
        try {
            // If another receive() is already waiting on this receiver, queue behind it instead of
            // taking the message it may have been woken up for. A closed channel wakes no one up
            // anymore, so never queue on it.
            if (null !== $this->suspension && !$this->state->isClosed()) {
                $this->waitForMessage($cancellation);
            }

            // Being woken up does not guarantee a message: another receiver may have taken it
            // before this fiber got to run, in which case we wait again.
            while ($this->state->isEmpty() && !$this->state->isClosed()) {
                $this->waitForMessage($cancellation);
            }

            return $this->state->receive();
        } finally {
            $this->suspension = null;
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
    private function waitForMessage(CancellationTokenInterface $cancellation): void
    {
        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $this->suspension = $suspension;
        $this->state->waitForMessage($suspension);

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                // The suspension may have already been resumed by a send, or thrown into by close().
                if ($this->state->removeFromWaitingForMessage($suspension)) {
                    $suspension->throw($e);
                }
            });
        }

        try {
            $suspension->suspend();
        } finally {
            if (null !== $id) {
                $cancellation->unsubscribe($id);
            }
        }
    }
}
