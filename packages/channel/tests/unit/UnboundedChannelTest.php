<?php

declare(strict_types=1);

namespace Psl\Channel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Channel;
use Psl\DateTime\Duration;
use RuntimeException;

final class UnboundedChannelTest extends TestCase
{
    public function testCapacity(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        static::assertNull($receiver->getCapacity());
        static::assertNull($sender->getCapacity());
    }

    public function testCloseSender(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

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
        [$receiver, $sender] = Channel\unbounded();

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
        [$receiver, $sender] = Channel\unbounded();

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
        [$receiver, $sender] = Channel\unbounded();

        static::assertFalse($receiver->isFull());
        static::assertFalse($sender->isFull());

        $sender->send('foo');

        static::assertFalse($receiver->isFull());
        static::assertFalse($sender->isFull());
    }

    public function testIsEmpty(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        static::assertTrue($receiver->isEmpty());
        static::assertTrue($sender->isEmpty());

        $sender->send('foo');

        static::assertFalse($receiver->isEmpty());
        static::assertFalse($sender->isEmpty());
    }

    public function testSendThrowsForClosedChannel(): void
    {
        /** @var Channel\SenderInterface<string> $sender */
        [$receiver, $sender] = Channel\unbounded();

        $receiver->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $sender->send('hello');
    }

    public function testReceiveThrowsForClosedEmptyChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $sender->close();

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $receiver->receive();
    }

    public function testTryReceiveThrowsForEmptyChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $_] = Channel\unbounded();

        $this->expectException(Channel\Exception\EmptyChannelException::class);

        $receiver->tryReceive();
    }

    public function testReceiveWaitsWhenChannelIsEmpty(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        Async\Scheduler::delay(Duration::milliseconds(1), static fn(): null => $sender->send('hello'));

        static::assertTrue($receiver->isEmpty());
        static::assertSame('hello', $receiver->receive());
    }

    public function testReceiveThrowsForLateClosedChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        Async\Scheduler::delay(Duration::milliseconds(1), static function () use ($sender): void {
            $sender->close();
        });

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $receiver->receive();
    }

    public function testReceiveCancelledWhileWaitingForMessage(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

        Async\run(static function () use ($sender): void {
            Async\sleep(Duration::seconds(5));
            $sender->send('never');
        })->ignore();

        $this->expectException(Async\Exception\CancelledException::class);

        $receiver->receive($token);
    }

    public function testReceiveCancelledWhileQueuedBehindPreviousReceive(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

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

    public function testReceiveWithAlreadyCancelledTokenOnEmptyChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        Async\run(static function () use ($sender): void {
            Async\sleep(Duration::seconds(5));
            $sender->send('never');
        })->ignore();

        $this->expectException(Async\Exception\CancelledException::class);

        $receiver->receive($token);
    }

    public function testReceiveWaitsForPreviousOperationsWhenChannelIsEmpty(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $one = Async\run($receiver->receive(...));
        $two = Async\run($receiver->receive(...));

        Async\Scheduler::defer(static fn(): null => $sender->send('foo'));
        Async\Scheduler::defer(static fn(): null => $sender->send('bar'));

        static::assertSame('bar', $two->await());
        static::assertTrue($one->isComplete());
        static::assertSame('foo', $one->await());
    }

    public function testReceiveResumedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $token = new Async\SignalCancellationToken();
        $receiving = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        // The receiver is resumed with a message, then its token is cancelled before it gets to run.
        $sender->send('hello');
        $token->cancel();

        static::assertSame('hello', $receiving->await());
    }

    public function testQueuedReceiveResumedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $token = new Async\SignalCancellationToken();
        $first = Async\run($receiver->receive(...));
        $second = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        $sender->send('foo');
        $sender->send('bar');
        $token->cancel();

        static::assertSame('foo', $first->await());
        static::assertSame('bar', $second->await());
    }

    public function testReceiveClosedAndCancelledInTheSameTick(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $token = new Async\SignalCancellationToken();
        $receiving = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        $sender->close();
        $token->cancel();

        $this->expectException(Channel\Exception\ClosedChannelException::class);

        $receiving->await();
    }

    public function testReceiveWaitsAgainWhenTheMessageItWasWokenForIsTaken(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

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

    public function testReceiveWokenUpThenCancelledThrowsCancelledExceptionWhenTheMessageIsTaken(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

        $token = new Async\SignalCancellationToken();
        $receiving = Async\run(static fn(): string => $receiver->receive($token));

        Async\later();

        $sender->send('foo');
        $token->cancel();
        static::assertSame('foo', $receiver->tryReceive());

        $this->expectException(Async\Exception\CancelledException::class);

        $receiving->await();
    }

    public function testReceiveDoesNotQueueBehindWaiterOfClosedChannel(): void
    {
        /** @var Channel\ReceiverInterface<string> $receiver */
        [$receiver, $sender] = Channel\unbounded();

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

    public function testReceiveQueuedBehindAWokenReceiverGetsTheMessageLeftOver(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

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

    public function testReceiveDoesNotQueueBehindACancelledReceiver(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

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

    public function testReceiveCancelledWhileSubscribingToTheToken(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        // The token is cancelled once the call is waiting, but before it suspends.
        $token = new SubscribeHookToken(static function (SubscribeHookToken $cancellation): void {
            $cancellation->cancel();
        });

        try {
            $receiver->receive($token);

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException $exception) {
            static::assertSame($token, $exception->getToken());
        }

        static::assertSame(0, $token->countSubscriptions());

        // The cancelled call no longer waits, so the next message is left for the next call.
        $sender->send('foo');

        static::assertSame('foo', $receiver->receive());
    }

    public function testReceiveWokenUpWhileSubscribingToTheToken(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        // A message arrives once the call is waiting, but before it suspends.
        $token = new SubscribeHookToken(static function () use ($sender): void {
            $sender->send('foo');
        });

        static::assertSame('foo', $receiver->receive($token));
        static::assertSame(0, $token->countSubscriptions());
        static::assertTrue($receiver->isEmpty());
    }

    public function testReceiveClosedWhileSubscribingToTheToken(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        // The channel is closed once the call is waiting, but before it suspends.
        $token = new SubscribeHookToken(static function () use ($sender): void {
            $sender->close();
        });

        try {
            $receiver->receive($token);

            static::fail('Expected ClosedChannelException to be thrown.');
        } catch (Channel\Exception\ClosedChannelException) {
            static::addToAssertionCount(1);
        }

        static::assertSame(0, $token->countSubscriptions());
    }

    public function testReceiveWithATokenThatFailsToSubscribe(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        $failure = new RuntimeException('Subscription failed.');
        $token = new SubscribeHookToken(static function () use ($failure): void {
            throw $failure;
        });

        $unrelated = new Async\Deferred();
        $fiber = Async\run(static function () use ($receiver, $token, $failure, $unrelated): string {
            try {
                $receiver->receive($token);

                static::fail('Expected the subscription failure to be thrown.');
            } catch (RuntimeException $exception) {
                static::assertSame($failure, $exception);
            }

            return $unrelated->getAwaitable()->await();
        });

        Async\later();

        // The failed call no longer waits: the next message neither resumes the fiber, which now
        // waits for something else, nor gets lost.
        $sender->send('foo');

        Async\later();

        static::assertFalse($fiber->isComplete());
        static::assertSame('foo', $receiver->receive());

        $unrelated->complete('bar');

        static::assertSame('bar', $fiber->await());
    }

    public function testReceiveWokenUpThenRobbedKeepsItsPlaceInLine(): void
    {
        /**
         * @var Channel\ReceiverInterface<string> $receiver
         * @var Channel\SenderInterface<string> $sender
         */
        [$receiver, $sender] = Channel\unbounded();

        $first = Async\run($receiver->receive(...));

        Async\later();

        $second = Async\run($receiver->receive(...));

        Async\later();

        // The first call is woken up for a message that is taken before it gets to run, so it waits again.
        $sender->send('foo');
        static::assertSame('foo', $receiver->tryReceive());

        Async\later();

        static::assertFalse($first->isComplete());
        static::assertFalse($second->isComplete());

        // It keeps its place in line: the next message is for it, and the one after for the second call.
        $sender->send('bar');
        $sender->send('baz');

        static::assertSame('bar', $first->await());
        static::assertSame('baz', $second->await());
    }
}
