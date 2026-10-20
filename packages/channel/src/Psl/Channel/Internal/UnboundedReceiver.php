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
final class UnboundedReceiver implements ReceiverInterface
{
    /**
     * @use ChannelSideTrait<UnboundedChannelState<T>>
     */
    use ChannelSideTrait;

    private null|Suspension $suspension = null;

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
        if ($this->suspension) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->suspension = $suspension;
            $this->state->waitForMessage($suspension);

            $id = null;
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                    // The suspension may have already been resumed by a send/receive, or thrown into by close().
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

        try {
            return $this->state->receive();
        } catch (Exception\EmptyChannelException) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->suspension = $suspension;
            $this->state->waitForMessage($suspension);

            $id2 = null;
            if ($cancellation->cancellable) {
                $id2 = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                    // The suspension may have already been resumed by a send/receive, or thrown into by close().
                    if ($this->state->removeFromWaitingForMessage($suspension)) {
                        $suspension->throw($e);
                    }
                });
            }

            try {
                $suspension->suspend();
            } finally {
                if (null !== $id2) {
                    $cancellation->unsubscribe($id2);
                }
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
}
