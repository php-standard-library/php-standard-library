<?php

declare(strict_types=1);

namespace Psl\Channel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Channel;
use Psl\DateTime;
use Psl\DateTime\Duration;

final class BoundedChannelTest extends TestCase
{
    public function testCapacity(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        static::assertSame(1, $receiver->getCapacity());
        static::assertSame(1, $sender->getCapacity());
    }

    public function testCloseSender(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        static::assertFalse($receiver->isClosed());
        static::assertFalse($sender->isClosed());

        $sender->close();

        static::assertTrue($receiver->isClosed());
        static::assertTrue($sender->isClosed());
    }

    public function testCloseReceiver(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        static::assertFalse($receiver->isClosed());
        static::assertFalse($sender->isClosed());

        $receiver->close();

        static::assertTrue($receiver->isClosed());
        static::assertTrue($sender->isClosed());
    }

    public function testCount(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(2);

        static::assertSame(0, $receiver->count());
        static::assertSame(0, $sender->count());

        $sender->send('foo');
        $sender->send('bar');

        static::assertSame(2, $receiver->count());
        static::assertSame(2, $sender->count());

        static::assertSame('foo', $receiver->receive());

        static::assertSame(1, $receiver->count());
        static::assertSame(1, $sender->count());

        static::assertSame('bar', $receiver->tryReceive());

        static::assertSame(0, $receiver->count());
        static::assertSame(0, $sender->count());

        $sender->trySend('baz');

        static::assertSame(1, $receiver->count());
        static::assertSame(1, $sender->count());
    }

    public function testIsFull(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        static::assertFalse($receiver->isFull());
        static::assertFalse($sender->isFull());

        $sender->send('foo');

        static::assertTrue($receiver->isFull());
        static::assertTrue($sender->isFull());
    }

    public function testTrySendThrowsOnFullChannel(): void
    {
        /**
         * @var Channel\SenderInterface<string> $sender
         */
        [$_, $sender] = Channel\bounded(1);

        $sender->send('hello');

        $this->expectException(Channel\Exception\FullChannelException::class);

        $sender->trySend('world');
    }

    public function testSendWaitsForFullChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('hello');

        Async\Scheduler::delay(DateTime\Duration::milliseconds(1), static function () use ($receiver): void {
            $receiver->receive();
        });

        static::assertTrue($receiver->isFull());

        $sender->send('world');
    }

    public function testSendThrowsForClosedChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $receiver->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to send a message to a closed channel.');

        $sender->send('world');
    }

    public function testSendThrowsForLateClosedChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('hello');

        Async\Scheduler::delay(DateTime\Duration::milliseconds(1), static function () use ($receiver): void {
            $receiver->close();
        });

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to send a message to a closed channel.');

        $sender->send('world');
    }

    public function testSendThrowsForLateClosedFullChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('hello');
        $receiver->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to send a message to a closed channel.');

        $sender->send('world');
    }

    public function testTrySendThrowsForClosedChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $receiver->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to send a message to a closed channel.');

        $sender->trySend('world');
    }

    public function testReceiveThrowsForClosedChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to receive a message from a closed empty channel.');

        $receiver->receive();
    }

    public function testReceiveThrowsForLateClosedChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        Async\Scheduler::delay(DateTime\Duration::milliseconds(1), static function () use ($sender): void {
            $sender->close();
        });

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to receive a message from a closed empty channel.');

        $receiver->receive();
    }

    public function testTryReceiveThrowsForClosedChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);
        $this->expectExceptionMessage('Attempted to receive a message from a closed empty channel.');

        $receiver->tryReceive();
    }

    public function testReceiveWaitsWhenChannelIsEmpty(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        Async\Scheduler::delay(DateTime\Duration::milliseconds(1), static fn(): null => $sender->send('hello'));

        static::assertTrue($receiver->isEmpty());

        static::assertSame('hello', $receiver->receive());
    }

    public function testTryReceiveThrowsForEmptyChannel(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         */
        [$receiver, $_] = Channel\bounded(1);

        $this->expectException(Channel\Exception\EmptyChannelException::class);
        $this->expectExceptionMessage('Attempted to receiver from an empty channel.');

        $receiver->tryReceive();
    }

    public function testSendWaitsForPreviousOperationWhenChannelIsFull(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        // fill the channel.
        $sender->send('foo');

        $one = Async\run(static fn(): null => $sender->send('bar'));
        $two = Async\run(static fn(): null => $sender->send('baz'));

        Async\Scheduler::defer(static function () use ($receiver): void {
            $receiver->receive();
        });

        Async\Scheduler::defer(static function () use ($receiver): void {
            $receiver->receive();
        });

        $two->await();

        static::assertTrue($one->isComplete());

        $one->await();
    }

    public function testReceiveWaitsForPreviousOperationsWhenChannelIsEmpty(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $one = Async\run($receiver->receive(...));
        $two = Async\run($receiver->receive(...));

        Async\Scheduler::defer(static fn(): null => $sender->send('foo'));
        Async\Scheduler::defer(static fn(): null => $sender->send('bar'));

        static::assertSame('bar', $two->await());

        static::assertTrue($one->isComplete());

        static::assertSame('foo', $one->await());
    }

    public function testReceiveCancelledWhileWaitingForMessage(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

        Async\run(static function () use ($sender): void {
            Async\sleep(Duration::seconds(5));
            $sender->send('never');
        })->ignore();

        $this->expectException(Async\Exception\CancelledException::class);

        $receiver->receive($token);
    }

    public function testSendCancelledWhileWaitingForSpace(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('fills-channel');

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

        Async\run(static function () use ($receiver): void {
            Async\sleep(Duration::seconds(5));
            $receiver->receive();
        })->ignore();

        $this->expectException(Async\Exception\CancelledException::class);

        $sender->send('blocked', $token);
    }

    public function testReceiveCancelledWhileQueuedBehindPreviousReceive(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $first = Async\run($receiver->receive(...));

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

        $second = Async\run(static fn(): string => $receiver->receive($token));

        Async\run(static function () use ($sender): void {
            Async\sleep(Duration::milliseconds(50));
            $sender->send('hello');
        })->ignore();

        try {
            $second->await();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('hello', $first->await());
    }

    public function testSendCancelledWhileQueuedBehindPreviousSend(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('fills');

        $first = Async\run(static function () use ($sender): void {
            $sender->send('queued-1');
        });

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

        $second = Async\run(static function () use ($sender, $token): void {
            $sender->send('queued-2', $token);
        });

        Async\run(static function () use ($receiver): void {
            Async\sleep(Duration::milliseconds(50));
            $receiver->receive();
            $receiver->receive();
        })->ignore();

        try {
            $second->await();
            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        $first->await();
    }

    public function testReceiveWithAlreadyCancelledTokenOnEmptyChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        Async\run(static function () use ($sender): void {
            Async\sleep(Duration::seconds(5));
            $sender->send('never');
        })->ignore();

        $this->expectException(Async\Exception\CancelledException::class);

        $receiver->receive($token);
    }

    public function testSendWithAlreadyCancelledTokenOnFullChannel(): void
    {
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('fills');

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        Async\run(static function () use ($receiver): void {
            Async\sleep(Duration::seconds(5));
            $receiver->receive();
        })->ignore();

        $this->expectException(Async\Exception\CancelledException::class);

        $sender->send('blocked', $token);
    }

    public function testReceiveResumedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $token = new Async\SignalCancellationToken();
        $receiving = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        // The receiver is resumed with a message, then its token is cancelled before it gets to run.
        $sender->send('hello');
        $token->cancel();

        static::assertSame('hello', $receiving->await());
    }

    public function testReceiveClosedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $token = new Async\SignalCancellationToken();
        $receiving = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        $sender->close();
        $token->cancel();

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $receiving->await();
    }

    public function testSendResumedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $token = new Async\SignalCancellationToken();
        $sending = Async\run(static fn(): null => $sender->send('bar', $token));

        Async\later();

        // The sender is resumed as space frees up, then its token is cancelled before it gets to run.
        static::assertSame('foo', $receiver->receive());
        $token->cancel();

        $sending->await();

        static::assertSame('bar', $receiver->receive());
    }

    public function testQueuedSendResumedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $token = new Async\SignalCancellationToken();
        $first = Async\run(static fn(): null => $sender->send('bar'));
        $second = Async\run(static fn(): null => $sender->send('baz', $token));

        Async\later();

        static::assertSame('foo', $receiver->receive());
        $first->await();
        static::assertSame('bar', $receiver->receive());
        $token->cancel();
        $second->await();

        static::assertSame('baz', $receiver->receive());
    }

    public function testSendClosedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\SenderInterface<string> $sender */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $token = new Async\SignalCancellationToken();
        $sending = Async\run(static fn(): null => $sender->send('bar', $token));

        Async\later();

        $receiver->close();
        $token->cancel();

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $sending->await();
    }

    public function testReceiveWaitsAgainWhenTheMessageItWasWokenForIsTaken(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $receiving = Async\run($receiver->receive(...));

        Async\later();

        // Wake the receiver up, then take the message before it gets to run.
        $sender->send('foo');
        static::assertSame('foo', $receiver->tryReceive());

        Async\later();

        static::assertFalse($receiving->isComplete());

        $sender->send('bar');

        static::assertSame('bar', $receiving->await());
    }

    public function testSendWaitsAgainWhenTheSpaceItWasWokenForIsTaken(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $sending = Async\run(static fn(): null => $sender->send('bar'));

        Async\later();

        // Wake the sender up, then take the free space before it gets to run.
        static::assertSame('foo', $receiver->receive());
        $sender->trySend('baz');

        Async\later();

        static::assertFalse($sending->isComplete());
        static::assertSame('baz', $receiver->receive());

        $sending->await();

        static::assertSame('bar', $receiver->receive());
    }

    public function testSendWokenUpThenCancelledThrowsCancelledExceptionWhenTheSpaceIsTaken(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $token = new Async\SignalCancellationToken();
        $sending = Async\run(static fn(): null => $sender->send('bar', $token));

        Async\later();

        static::assertSame('foo', $receiver->receive());
        $token->cancel();
        $sender->trySend('baz');

        try {
            $sending->await();

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame('baz', $receiver->receive());
        static::assertTrue($receiver->isEmpty());
    }

    public function testReceiveDoesNotQueueBehindWaiterOfClosedChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\bounded(1);

        $waiting = Async\run($receiver->receive(...));

        Async\later();

        // Closing wakes the waiting receiver up, but it has not run yet when receive() is called again.
        $sender->close();

        try {
            $receiver->receive(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));

            static::fail('Expected ClosedChannelException to be thrown.');
        } catch (Channel\Exception\ClosedChannelException) {
            static::addToAssertionCount(1);
        }

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $waiting->await();
    }

    public function testSendDoesNotQueueBehindWaiterOfClosedChannel(): void
    {
        /** @var Channel\SenderInterface<string> $sender */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $waiting = Async\run(static fn(): null => $sender->send('bar'));

        Async\later();

        // Closing wakes the waiting sender up, but it has not run yet when send() is called again.
        $receiver->close();

        try {
            $sender->send('baz', new Async\TimeoutCancellationToken(Duration::milliseconds(100)));

            static::fail('Expected ClosedChannelException to be thrown.');
        } catch (Channel\Exception\ClosedChannelException) {
            static::addToAssertionCount(1);
        }

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $waiting->await();
    }

    public function testReceiveQueuedBehindAWokenReceiverGetsTheMessageLeftOver(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(5);

        $waiting = Async\run($receiver->receive(...));

        Async\later();

        // The first message wakes the waiting receiver up, which has not run yet when receive() is
        // called again, and queues behind it; the second message is left for the queued call.
        $sender->send('foo');
        $sender->send('bar');

        static::assertSame('bar', $receiver->receive(new Async\TimeoutCancellationToken(Duration::milliseconds(100))));
        static::assertSame('foo', $waiting->await());
        static::assertTrue($receiver->isEmpty());
    }

    public function testSendQueuedBehindAWokenSenderGetsTheSpaceLeftOver(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(2);

        $sender->send('foo');
        $sender->send('bar');

        $waiting = Async\run(static fn(): null => $sender->send('baz'));

        Async\later();

        // The first receive wakes the waiting sender up, which has not run yet when send() is called
        // again, and queues behind it; the space freed by the second receive is left for the queued call.
        static::assertSame('foo', $receiver->receive());
        static::assertSame('bar', $receiver->receive());

        $sender->send('qux', new Async\TimeoutCancellationToken(Duration::milliseconds(100)));
        $waiting->await();

        static::assertSame(2, $sender->count());
        static::assertSame('baz', $receiver->receive());
        static::assertSame('qux', $receiver->receive());
    }

    public function testReceiveDoesNotQueueBehindACancelledReceiver(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(5);

        $token = new Async\SignalCancellationToken();
        $cancelled = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        // The waiting receiver is cancelled, but has not run yet when receive() is called again.
        $token->cancel();
        $sender->send('foo');

        static::assertSame('foo', $receiver->receive(new Async\TimeoutCancellationToken(Duration::milliseconds(100))));

        $this->expectException(Async\Exception\CancelledException::class);

        $cancelled->await();
    }

    public function testSendDoesNotQueueBehindACancelledSender(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\bounded(1);

        $sender->send('foo');

        $token = new Async\SignalCancellationToken();
        $cancelled = Async\run(static fn(): null => $sender->send('bar', $token));

        Async\later();

        // The waiting sender is cancelled, but has not run yet when send() is called again.
        $token->cancel();
        static::assertSame('foo', $receiver->receive());

        $sender->send('baz', new Async\TimeoutCancellationToken(Duration::milliseconds(100)));

        static::assertSame('baz', $receiver->receive());

        $this->expectException(Async\Exception\CancelledException::class);

        $cancelled->await();
    }
}
