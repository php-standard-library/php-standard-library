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
use Revolt\EventLoop\Suspension;

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
    public function send(mixed $message, CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        try {
            // If another send() is already waiting on this sender, queue behind it instead of
            // taking the space it may have been woken up for.
            if (null !== $this->suspension) {
                $this->waitForSpace($cancellation);
            }

            // Being woken up does not guarantee space: another sender may have taken it
            // before this fiber got to run, in which case we wait again.
            while ($this->state->isFull() && !$this->state->isClosed()) {
                $this->waitForSpace($cancellation);
            }

            $this->state->send($message);
        } finally {
            $this->suspension = null;
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
    private function waitForSpace(CancellationTokenInterface $cancellation): void
    {
        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $suspension = EventLoop::getSuspension();
        $this->suspension = $suspension;
        $this->state->waitForSpace($suspension);

        $id = null;
        if ($cancellation->cancellable) {
            $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                // The suspension may have already been resumed by a receive, or thrown into by close().
                if ($this->state->removeFromWaitingForSpace($suspension)) {
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
