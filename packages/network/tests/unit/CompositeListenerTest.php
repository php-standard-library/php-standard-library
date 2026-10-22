<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use Closure;
use Error;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Psl\Network\Tests\Fixture\FakeListener;
use Revolt\EventLoop;
use Throwable;

use function gc_collect_cycles;

final class CompositeListenerTest extends TestCase
{
    /**
     * How long a test waits for a call that should already have ended, in milliseconds.
     */
    private const int SETTLE_TIMEOUT_MILLISECONDS = 100;

    public function testInnerAcceptErrorIsThrownToTheCaller(): void
    {
        $failing = new FakeListener();
        $healthy = new FakeListener();
        $composite = new Network\CompositeListener([$failing, $healthy]);

        $error = new Network\Exception\RuntimeException('TLS handshake failed.');
        $failing->push($error);

        try {
            $composite->accept();

            static::fail('Expected the inner accept() error to be thrown.');
        } catch (Network\Exception\RuntimeException $exception) {
            static::assertSame($error, $exception);
        }

        static::assertFalse($composite->isClosed());

        // The composite listener keeps accepting from all listeners.
        [$server, $client] = Network\socket_pair();
        $healthy->push($server);

        static::assertSame($server, $composite->accept());

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testInnerAcceptErrorIsKeptForTheNextCallWhenNobodyIsWaiting(): void
    {
        $first = new FakeListener();
        $second = new FakeListener();
        $composite = new Network\CompositeListener([$first, $second]);

        [$server, $client] = Network\socket_pair();
        $first->push($server);

        static::assertSame($server, $composite->accept());

        Async\later();

        // The inner accept of the second listener is still in flight, and fails while nobody is waiting.
        static::assertSame(1, $second->waiting);

        $error = new Network\Exception\RuntimeException('Failed to accept incoming connection.');
        $second->push($error);

        Async\later();

        try {
            $composite->accept(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));

            static::fail('Expected the inner accept() error to be thrown.');
        } catch (Network\Exception\RuntimeException $exception) {
            static::assertSame($error, $exception);
        }

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testStoppedInnerListenerDoesNotStopTheOthers(): void
    {
        $stopped = new FakeListener();
        $stopped->close();

        $healthy = new FakeListener();
        $composite = new Network\CompositeListener([$stopped, $healthy]);

        [$server, $client] = Network\socket_pair();
        $healthy->push($server);

        static::assertSame($server, $composite->accept());

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testEveryWaitingCallIsToldWhenAllInnerListenersAreStopped(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $first = Async\run($composite->accept(...));
        $second = Async\run($composite->accept(...));

        Async\later();

        $listener->close();

        foreach ([$first, $second] as $accepting) {
            try {
                $accepting->await();

                static::fail('Expected AlreadyStoppedException to be thrown.');
            } catch (Network\Exception\AlreadyStoppedException $exception) {
                static::assertSame('All listeners have been stopped.', $exception->getMessage());
            }
        }

        static::assertFalse($composite->isClosed());

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        $composite->accept();
    }

    public function testCancellingTheLastWaitingCallCancelsInnerAccepts(): void
    {
        $first = new FakeListener();
        $second = new FakeListener();
        $composite = new Network\CompositeListener([$first, $second]);

        $token = new Async\SignalCancellationToken();
        $accepting = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));

        Async\later();
        Async\later();

        static::assertSame(1, $first->waiting);
        static::assertSame(1, $second->waiting);

        $token->cancel();

        try {
            $accepting->await();

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        Async\later();

        static::assertSame(0, $first->waiting);
        static::assertSame(0, $second->waiting);

        $composite->close();
    }

    public function testCancellingEveryWaitingCallCancelsInnerAccepts(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $token = new Async\SignalCancellationToken();
        $first = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));
        $second = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        $token->cancel();

        foreach ([$first, $second] as $cancelled) {
            try {
                $cancelled->await();

                static::fail('Expected CancelledException to be thrown.');
            } catch (Async\Exception\CancelledException) {
                static::addToAssertionCount(1);
            }
        }

        Async\later();

        static::assertSame(0, $listener->waiting);

        $composite->close();
    }

    public function testCancellingOneOfManyWaitingCallsKeepsInnerAcceptsRunning(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $token = new Async\SignalCancellationToken();
        $cancelled = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));
        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        $token->cancel();

        try {
            $cancelled->await();

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame(1, $listener->waiting);

        [$server, $client] = Network\socket_pair();
        $listener->push($server);

        static::assertSame($server, $accepting->await());

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testCancellingAWaitingCallKeepsInnerAcceptsRunningWhileAnotherCallIsWokenUp(): void
    {
        $first = new FakeListener();
        $second = new FakeListener();
        $composite = new Network\CompositeListener([$first, $second]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        $token = new Async\SignalCancellationToken();
        $cancelled = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));

        Async\later();

        static::assertSame(1, $first->waiting);
        static::assertSame(1, $second->waiting);

        // The connection accepted by the first listener wakes up the first waiting call, and the other
        // waiting call is cancelled before the woken call gets to run.
        $first->beforeDelivery = static function () use ($token): void {
            Async\Scheduler::queue($token->cancel(...));
        };

        [$server, $client] = Network\socket_pair();
        $first->push($server);

        static::assertSame($server, $accepting->await());

        try {
            $cancelled->await();

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        Async\later();

        // The inner accept of the second listener was not cancelled.
        static::assertSame(1, $second->waiting);

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testCancellingTheLastCallAfterItIsWokenUpCancelsInnerAcceptsWhenItsConnectionIsTaken(): void
    {
        $first = new FakeListener();
        $second = new FakeListener();
        $composite = new Network\CompositeListener([$first, $second]);

        $token = new Async\SignalCancellationToken();
        $accepting = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));

        Async\later();
        Async\later();

        static::assertSame(1, $first->waiting);
        static::assertSame(1, $second->waiting);

        // The connection accepted by the first listener wakes the waiting call up. Before it gets to run,
        // the call is cancelled, and the connection is taken from the backlog by another call.
        $stolen = null;
        $first->beforeDelivery = static function () use ($composite, $token, &$stolen): void {
            Async\Scheduler::queue(static function () use ($composite, $token, &$stolen): void {
                $token->cancel();
                $stolen = $composite->accept();
            });
        };

        [$server, $client] = Network\socket_pair();
        $first->push($server);

        try {
            $accepting->await();

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        static::assertSame($server, $stolen);

        Async\later();

        // Nobody is waiting anymore: the inner accept of the second listener was cancelled.
        static::assertSame(0, $second->waiting);

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testAcceptRightAfterACancelledAcceptStartsNewInnerAccepts(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $token = new Async\SignalCancellationToken();
        $accepting = Async\run(static function () use ($composite, $token): Network\StreamInterface {
            try {
                $composite->accept($token);

                static::fail('Expected CancelledException to be thrown.');
            } catch (Async\Exception\CancelledException) {
                // Accept again before the cancelled inner accept had a chance to unwind.
                return $composite->accept();
            }
        });

        Async\later();
        Async\later();

        $token->cancel();

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        [$server, $client] = Network\socket_pair();
        $listener->push($server);

        static::assertSame($server, $accepting->await());

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testCloseAndCancellationInTheSameTick(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $token = new Async\SignalCancellationToken();
        $accepting = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));

        Async\later();

        // close() wakes the waiting call up, then its token is cancelled before it gets to run.
        $composite->close();
        $token->cancel();

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        $accepting->await();
    }

    public function testWokenCallWaitsAgainWhenItsConnectionIsTaken(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        [$server, $client] = Network\socket_pair();

        // Take the accepted connection from the backlog before the waiting call gets to run.
        $stolen = null;
        $listener->beforeDelivery = static function () use ($composite, &$stolen): void {
            Async\Scheduler::queue(static function () use ($composite, &$stolen): void {
                $stolen = $composite->accept();
            });
        };

        $listener->push($server);

        Async\later();
        Async\later();

        static::assertSame($server, $stolen);
        static::assertFalse($accepting->isComplete());

        $listener->beforeDelivery = null;

        [$otherServer, $otherClient] = Network\socket_pair();
        $listener->push($otherServer);

        static::assertSame($otherServer, $accepting->await());

        $client->close();
        $server->close();
        $otherClient->close();
        $otherServer->close();
        $composite->close();
    }

    public function testEveryWaitingCallGetsAConnection(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $first = Async\run($composite->accept(...));
        $second = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        [$server, $client] = Network\socket_pair();
        $listener->push($server);

        Async\later();
        Async\later();

        // The inner accept is started again for the call still waiting, without waiting for a new call.
        static::assertSame(1, $listener->waiting);

        [$otherServer, $otherClient] = Network\socket_pair();
        $listener->push($otherServer);

        static::assertSame($server, $first->await());
        static::assertSame(
            $otherServer,
            $second->await(new Async\TimeoutCancellationToken(Duration::milliseconds(100))),
        );

        $client->close();
        $server->close();
        $otherClient->close();
        $otherServer->close();
        $composite->close();
    }

    public function testFailedInnerAcceptIsStartedAgainForTheCallsStillWaiting(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $first = Async\run($composite->accept(...));
        $second = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        $error = new Network\Exception\RuntimeException('TLS handshake failed.');
        $listener->push($error);

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        [$server, $client] = Network\socket_pair();
        $listener->push($server);

        try {
            $first->await();

            static::fail('Expected the inner accept() error to be thrown.');
        } catch (Network\Exception\RuntimeException $exception) {
            static::assertSame($error, $exception);
        }

        static::assertSame($server, $second->await(new Async\TimeoutCancellationToken(Duration::milliseconds(100))));

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testWaitingCallIsNotDestroyedByTheCycleCollector(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        // Only the composite listener references the suspension of the waiting call, and its fiber.
        gc_collect_cycles();

        [$server, $client] = Network\socket_pair();
        $listener->push($server);

        static::assertSame($server, $accepting->await(new Async\TimeoutCancellationToken(Duration::milliseconds(100))));

        $client->close();
        $server->close();
        $composite->close();
    }

    public function testConnectionAcceptedAfterCloseIsClosed(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        [$server, $client] = Network\socket_pair();

        $listener->beforeDelivery = static function () use ($composite): void {
            $composite->close();
        };

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        $listener->push($server);

        try {
            $accepting->await();

            static::fail('Expected AlreadyStoppedException to be thrown.');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }

        static::assertTrue($server->isClosed());

        $client->close();
    }

    /**
     * Closing the composite listener closes every inner listener, even after one of them fails to close, then throws
     * the first failure.
     */
    public function testCloseClosesEveryInnerListenerWhenOneFailsToClose(): void
    {
        $failure = new IO\Exception\RuntimeException('Failed to close the listener.');
        $failing = new FakeListener();
        $failing->closeFailure = $failure;
        $other = new FakeListener();
        $composite = new Network\CompositeListener([$failing, $other]);

        try {
            $composite->close();

            static::fail('Expected the close failure to be thrown.');
        } catch (IO\Exception\RuntimeException $exception) {
            static::assertSame($failure, $exception);
        }

        static::assertTrue($other->isClosed());
        static::assertTrue($composite->isClosed());

        // Closing again does nothing, and does not throw the failure again.
        $composite->close();
    }

    /**
     * A waiting accept() call learns that the composite listener stopped, even when closing an inner listener fails.
     */
    public function testCloseWakesUpWaitingCallsWhenAnInnerListenerFailsToClose(): void
    {
        $failure = new IO\Exception\RuntimeException('Failed to close the listener.');
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        $listener->closeFailure = $failure;

        try {
            $composite->close();

            static::fail('Expected the close failure to be thrown.');
        } catch (IO\Exception\RuntimeException $exception) {
            static::assertSame($failure, $exception);
        }

        try {
            $accepting->await(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

            static::fail('Expected AlreadyStoppedException to be thrown.');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }

        Async\later();

        // The inner accept was cancelled too.
        static::assertSame(0, $listener->waiting);
    }

    /**
     * Closing the composite listener closes every connection left in its backlog, even after one of them fails to
     * close, and every inner listener, then throws the first failure.
     */
    public function testCloseClosesEveryBufferedConnectionWhenOneFailsToClose(): void
    {
        $first = new FakeListener();
        $second = new FakeListener();
        $third = new FakeListener();
        $composite = new Network\CompositeListener([$first, $second, $third]);

        $failure = new IO\Exception\RuntimeException('Failed to close the connection.');
        $failing = $this->createMock(Network\StreamInterface::class);
        $failing->expects(static::once())->method('close')->willThrowException($failure);

        [$accepted, $acceptedClient] = Network\socket_pair();
        [$buffered, $bufferedClient] = Network\socket_pair();

        // The first listener's connection goes to the caller; the other two inner accepts stay in flight, and the
        // connections they return wait in the backlog.
        $first->push($accepted);
        static::assertSame($accepted, $composite->accept());

        Async\later();

        static::assertSame(1, $second->waiting);
        static::assertSame(1, $third->waiting);

        $second->push($failing);
        $third->push($buffered);

        Async\later();

        try {
            $composite->close();

            static::fail('Expected the close failure to be thrown.');
        } catch (IO\Exception\RuntimeException $exception) {
            static::assertSame($failure, $exception);
        }

        static::assertTrue($buffered->isClosed());
        static::assertFalse($accepted->isClosed());
        static::assertTrue($first->isClosed());
        static::assertTrue($second->isClosed());
        static::assertTrue($third->isClosed());

        $accepted->close();
        $acceptedClient->close();
        $bufferedClient->close();
    }

    /**
     * An `Error` that an inner listener throws from close() is a defect: closing the composite listener throws it
     * as-is, and has already woken up the waiting accept() calls and cancelled the in-flight inner accepts.
     */
    public function testCloseThrowsAnErrorOfAnInnerListenerAfterSettlingTheWaitingCalls(): void
    {
        $defect = new Error('The inner listener has a defect.');
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        $listener->closeFailure = $defect;

        try {
            $composite->close();

            static::fail('Expected the Error to be thrown.');
        } catch (Error $exception) {
            static::assertSame($defect, $exception);
        }

        try {
            $accepting->await(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

            static::fail('Expected AlreadyStoppedException to be thrown.');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }

        Async\later();

        static::assertSame(0, $listener->waiting);
    }

    /**
     * A connection that an inner accept returns after close() is closed, and an expected failure to close it does
     * not reach the event loop, since nobody is there to be told.
     */
    public function testFailureToCloseAConnectionAcceptedAfterCloseIsSuppressed(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);

        $connection = $this->createMock(Network\StreamInterface::class);
        $connection
            ->expects(static::once())
            ->method('close')
            ->willThrowException(new IO\Exception\RuntimeException('Failed to close the connection.'));

        $listener->beforeDelivery = static function () use ($composite): void {
            $composite->close();
        };

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        $listener->push($connection);

        try {
            $accepting->await(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

            static::fail('Expected AlreadyStoppedException to be thrown.');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }

        Async\later();
    }

    /**
     * An `Error` that an inner accept throws once the composite listener has cancelled it, because its last waiting
     * call was cancelled, is not dropped with the cancellation: the next accept() call throws it.
     */
    public function testErrorThatEndsACancelledInnerAcceptIsThrownToTheNextCall(): void
    {
        $defect = new Error('The inner listener has a defect.');
        $listener = new FakeListener();
        $listener->cancellationFailure = $defect;
        $composite = new Network\CompositeListener([$listener]);

        $this->cancelTheOnlyWaitingCall($composite, $listener);

        try {
            $composite->accept(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

            static::fail('Expected the Error to be thrown.');
        } catch (Error $exception) {
            static::assertSame($defect, $exception);
        }

        $composite->close();
    }

    /**
     * An `Error` that an inner accept throws once close() has cancelled it can reach no accept() call anymore: it
     * reaches the event loop instead of being dropped.
     */
    public function testErrorThatEndsAnInnerAcceptAfterCloseReachesTheEventLoop(): void
    {
        $defect = new Error('The inner listener has a defect.');
        $listener = new FakeListener();
        $listener->cancellationFailure = $defect;
        $composite = new Network\CompositeListener([$listener]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        $errors = self::recordEventLoopErrors(static function () use ($composite): void {
            $composite->close();

            // The cancelled inner accept ends in one turn of the event loop, and what it reports runs in the next.
            Async\later();
            Async\later();
        });

        try {
            $accepting->await(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

            static::fail('Expected AlreadyStoppedException to be thrown.');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }

        static::assertSame([$defect], $errors);
        static::assertSame(0, $listener->waiting);
    }

    /**
     * An `Error` left in the backlog when the composite listener closes can reach no accept() call anymore: it reaches
     * the event loop instead of being dropped.
     */
    public function testErrorLeftInTheBacklogReachesTheEventLoopOnClose(): void
    {
        $defect = new Error('The inner listener has a defect.');
        $listener = new FakeListener();
        $listener->cancellationFailure = $defect;
        $composite = new Network\CompositeListener([$listener]);

        $this->cancelTheOnlyWaitingCall($composite, $listener);

        $errors = self::recordEventLoopErrors(static function () use ($composite): void {
            $composite->close();

            Async\later();
        });

        static::assertSame([$defect], $errors);
    }

    /**
     * An expected accept failure that an inner accept throws once close() has cancelled it is dropped, like the
     * connection it could have returned is closed: it does not fail the event loop.
     */
    public function testExpectedFailureThatEndsAnInnerAcceptAfterCloseIsDropped(): void
    {
        $listener = new FakeListener();
        $listener->cancellationFailure = new Network\Exception\RuntimeException(
            'Failed to accept incoming connection.',
        );
        $composite = new Network\CompositeListener([$listener]);

        $accepting = Async\run($composite->accept(...));

        Async\later();
        Async\later();

        $errors = self::recordEventLoopErrors(static function () use ($composite): void {
            $composite->close();

            // The cancelled inner accept ends in one turn of the event loop, and what it reports runs in the next.
            Async\later();
            Async\later();
        });

        try {
            $accepting->await(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

            static::fail('Expected AlreadyStoppedException to be thrown.');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }

        static::assertSame([], $errors);
        static::assertSame(0, $listener->waiting);
    }

    /**
     * Wait in an accept() call, the only one, until the inner accept of the given listener is in flight, then cancel
     * the call, which cancels that inner accept, and let the inner accept end.
     */
    private function cancelTheOnlyWaitingCall(Network\CompositeListener $composite, FakeListener $listener): void
    {
        $token = new Async\SignalCancellationToken();
        $accepting = Async\run(static fn(): Network\StreamInterface => $composite->accept($token));

        Async\later();
        Async\later();

        static::assertSame(1, $listener->waiting);

        $token->cancel();

        try {
            $accepting->await();

            static::fail('Expected CancelledException to be thrown.');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        }

        Async\later();

        static::assertSame(0, $listener->waiting);
    }

    /**
     * Run the given operation with an event loop error handler that records every failure reaching it, and return
     * those failures, oldest first.
     *
     * @param Closure(): void $operation
     *
     * @return list<Throwable>
     */
    private static function recordEventLoopErrors(Closure $operation): array
    {
        $errors = [];
        $previous = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });

        try {
            $operation();
        } finally {
            EventLoop::setErrorHandler($previous);
        }

        return $errors;
    }
}
