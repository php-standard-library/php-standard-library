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
        if ($this->suspension) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->suspension = $suspension;
            $this->state->waitForSpace($suspension);

            $id = null;
            if ($cancellation->cancellable) {
                $id = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                    // The suspension may have already been resumed by a send/receive, or thrown into by close().
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

        try {
            $this->state->send($message);
        } catch (Exception\FullChannelException) {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $suspension = EventLoop::getSuspension();
            $this->suspension = $suspension;
            $this->state->waitForSpace($suspension);

            $id2 = null;
            if ($cancellation->cancellable) {
                $id2 = $cancellation->subscribe(function (CancelledException $e) use ($suspension): void {
                    // The suspension may have already been resumed by a send/receive, or thrown into by close().
                    if ($this->state->removeFromWaitingForSpace($suspension)) {
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
}
