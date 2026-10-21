<?php

declare(strict_types=1);

namespace Psl\Channel\Internal;

use Override;
use Psl\Channel\ChannelInterface;
use Psl\Channel\Exception;

use function array_search;
use function array_shift;
use function array_splice;
use function count;

/**
 * @template T
 *
 * @internal
 */
final class UnboundedChannelState implements ChannelInterface
{
    /**
     * @var list<Waiter>
     */
    private array $waitingForMessage = [];

    /**
     * @var array<array-key, T>
     */
    private array $messages = [];

    private bool $closed = false;

    public function waitForMessage(Waiter $waiter): void
    {
        $waiter->waiting = true;
        $this->waitingForMessage[] = $waiter;
    }

    /**
     * Remove the given waiter from the waiting list.
     *
     * Returns `false` if the waiter was no longer waiting, meaning it has already been
     * woken up (or failed) and must not be settled again.
     */
    public function removeFromWaitingForMessage(Waiter $waiter): bool
    {
        if (!$waiter->waiting) {
            return false;
        }

        $index = array_search($waiter, $this->waitingForMessage, true);
        if (false === $index) {
            return false;
        }

        array_splice($this->waitingForMessage, $index, 1);
        $waiter->waiting = false;

        return true;
    }

    /**
     * @return null
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getCapacity(): null
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        $this->closed = true;

        $waiters = $this->waitingForMessage;
        $this->waitingForMessage = [];
        foreach ($waiters as $waiter) {
            $waiter->fail(Exception\ClosedChannelException::forReceiving());
        }
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @return int<0, max>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function isFull(): bool
    {
        return false;
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function isEmpty(): bool
    {
        return !$this->messages;
    }

    /**
     * @param T $message
     *
     * @throws Exception\ClosedChannelException If the channel is closed.
     * @throws Exception\FullChannelException If the channel is full.
     */
    public function send(mixed $message): void
    {
        if ($this->closed) {
            throw Exception\ClosedChannelException::forSending();
        }

        $this->messages[] = $message;
        array_shift($this->waitingForMessage)?->wake();
    }

    /**
     * @throws Exception\ClosedChannelException If the channel is closed, and there's no more messages to receive.
     * @throws Exception\EmptyChannelException If the channel is empty.
     *
     * @return T
     */
    public function receive(): mixed
    {
        if (!$this->messages) {
            if ($this->closed) {
                throw Exception\ClosedChannelException::forReceiving();
            }

            throw Exception\EmptyChannelException::create();
        }

        $item = array_shift($this->messages);

        // Receivers may have queued behind one that was woken up for a message; if messages
        // are left, wake the next one up, as no send() is going to.
        if ($this->messages !== []) {
            array_shift($this->waitingForMessage)?->wake();
        }

        return $item;
    }
}
