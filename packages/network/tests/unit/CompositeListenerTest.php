<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\Network\Tests\Fixture\FakeListener;

final class CompositeListenerTest extends TestCase
{
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
}
