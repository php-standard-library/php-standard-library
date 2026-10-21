<?php

declare(strict_types=1);

namespace Psl\Channel\Internal;

use Override;
use Psl\Channel\ChannelInterface;
use Psl\Channel\Exception;

use function array_search;
use function array_shift;
use function array_splice;

/**
 * @template T
 *
 * @internal
 */
final class BoundedChannelState implements ChannelInterface
{
    /**
     * @var list<Waiter>
     */
    private array $waitingForMessage = [];

    /**
     * @var list<Waiter>
     */
    private array $waitingForSpace = [];

    /**
     * The place in line given to the last waiter that started waiting.
     */
    private int $lastTicket = 0;

    /**
     * @var array<array-key, T>
     */
    private array $messages = [];

    /**
     * @var int<0, max>
     */
    private int $size = 0;

    public bool $closed = false;

    /**
     * @var positive-int
     */
    private readonly int $capacity;

    /**
     * @param positive-int $capacity
     */
    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function waitForSpace(Waiter $waiter): void
    {
        $waiter->waiting = true;
        if (0 !== $waiter->ticket) {
            // A call woken up for space that another call took before it got to run keeps its
            // place in line.
            $this->waitingForSpace = self::insertInLine($this->waitingForSpace, $waiter);

            return;
        }

        $this->lastTicket++;
        $waiter->ticket = $this->lastTicket;
        $this->waitingForSpace[] = $waiter;
    }

    public function waitForMessage(Waiter $waiter): void
    {
        $waiter->waiting = true;
        if (0 !== $waiter->ticket) {
            // A call woken up for a message that another call took before it got to run keeps its
            // place in line.
            $this->waitingForMessage = self::insertInLine($this->waitingForMessage, $waiter);

            return;
        }

        $this->lastTicket++;
        $waiter->ticket = $this->lastTicket;
        $this->waitingForMessage[] = $waiter;
    }

    /**
     * @return positive-int
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function close(): void
    {
        $this->closed = true;

        $waiters = $this->waitingForSpace;
        $this->waitingForSpace = [];
        foreach ($waiters as $waiter) {
            $waiter->fail(Exception\ClosedChannelException::forSending());
        }

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
        return $this->size;
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function isFull(): bool
    {
        return $this->capacity === $this->size;
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
     * Remove the given waiter from the waiting list.
     *
     * Returns `false` if the waiter was no longer waiting, meaning it has already been
     * woken up (or failed) and must not be settled again.
     */
    public function removeFromWaitingForSpace(Waiter $waiter): bool
    {
        if (!$waiter->waiting) {
            return false;
        }

        $index = array_search($waiter, $this->waitingForSpace, true);
        if (false === $index) {
            return false;
        }

        array_splice($this->waitingForSpace, $index, 1);
        $waiter->waiting = false;

        return true;
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

        if ($this->capacity === $this->size) {
            throw Exception\FullChannelException::ofCapacity($this->capacity);
        }

        $this->messages[] = $message;
        $this->size++;

        array_shift($this->waitingForMessage)?->wake();

        // Senders may have queued behind one that was woken up for space; if space is
        // left, wake the next one up, as no receive() is going to.
        if ($this->size < $this->capacity) {
            array_shift($this->waitingForSpace)?->wake();
        }
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
        // @mago-expect analysis:invalid-property-assignment-value - The size is always in sync with messages.
        $this->size--;

        array_shift($this->waitingForSpace)?->wake();

        // Receivers may have queued behind one that was woken up for a message; if messages
        // are left, wake the next one up, as no send() is going to.
        if ($this->messages !== []) {
            array_shift($this->waitingForMessage)?->wake();
        }

        return $item;
    }

    /**
     * Insert the given waiter in the given wait list, ahead of every waiter that came after it.
     *
     * @param list<Waiter> $waiters
     *
     * @return list<Waiter>
     */
    private static function insertInLine(array $waiters, Waiter $waiter): array
    {
        foreach ($waiters as $index => $other) {
            if ($other->ticket < $waiter->ticket) {
                continue;
            }

            array_splice($waiters, $index, 0, [$waiter]);

            return $waiters;
        }

        $waiters[] = $waiter;

        return $waiters;
    }
}
