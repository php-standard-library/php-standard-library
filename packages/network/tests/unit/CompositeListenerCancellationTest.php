<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Network;
use Psl\Network\Tests\Fixture\FakeListener;

/**
 * A waiting `CompositeListener::accept()` call settles once, and its cancellation listener reaches only that wait: not
 * before the wait has suspended, and not a later wait of the same fiber, which the event loop hands the same
 * suspension.
 */
final class CompositeListenerCancellationTest extends TestCase
{
    /**
     * A deadline that passes between the accept's check and its subscribe fails the accept with the token's
     * exception, and leaves no inner accept running for nobody.
     */
    public function testAcceptFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);
        $token = new ExpiringCancellationToken();

        static::assertSame($token->exception, self::acceptFailure($composite, $token));

        self::assertNoInnerAcceptIsLeftAndTheListenerStillAccepts($composite, $listener);
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the accept with the token's exception, and leaves
     * no inner accept running for nobody.
     */
    public function testAcceptFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);
        $token = new InlineCancellationToken();

        static::assertSame($token->exception, self::acceptFailure($composite, $token));

        self::assertNoInnerAcceptIsLeftAndTheListenerStillAccepts($composite, $listener);
    }

    /**
     * The listener of an accept that has returned, notified from a snapshot taken while it waited, does not end the
     * next accept of the same fiber.
     */
    public function testLateCancellationOfAnEarlierAcceptDoesNotEndALaterAccept(): void
    {
        $listener = new FakeListener();
        $composite = new Network\CompositeListener([$listener]);
        $token = new DeferredCancellationToken();
        $firstAccept = new Async\Deferred();

        [$firstServer, $firstClient] = Network\socket_pair();
        [$secondServer, $secondClient] = Network\socket_pair();

        try {
            $acceptor = Async\run(static function () use ($composite, $token, $firstAccept): Network\StreamInterface {
                $firstAccept->complete($composite->accept($token));

                // A second wait of the same fiber, which does not use the token.
                return $composite->accept();
            });

            // The acceptor waits in its first accept, subscribed to the token, and its inner accept is in flight. The
            // token is cancelled, but its listeners are notified only later, after that accept has returned the
            // connection pushed below.
            Async\later();
            Async\later();
            $token->cancel();
            $listener->push($firstServer);
            static::assertSame($firstServer, $firstAccept->getAwaitable()->await());

            // The acceptor now waits in its second accept; the first accept's listener must not end it.
            $token->deliver();
            Async\later();
            Async\later();
            $listener->push($secondServer);

            static::assertSame($secondServer, $acceptor->await());
        } finally {
            $firstClient->close();
            $firstServer->close();
            $secondClient->close();
            $secondServer->close();
            $composite->close();
        }
    }

    /**
     * Accept with the given token, and return the cancellation it failed with.
     */
    private static function acceptFailure(
        Network\CompositeListener $composite,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $composite->accept($cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The accept was not cancelled.');
    }

    /**
     * Assert that the cancelled call left no inner accept in flight, and that the composite listener still hands out
     * the next connection.
     */
    private static function assertNoInnerAcceptIsLeftAndTheListenerStillAccepts(
        Network\CompositeListener $composite,
        FakeListener $listener,
    ): void {
        Async\later();
        Async\later();

        static::assertSame(0, $listener->waiting);

        [$server, $client] = Network\socket_pair();
        $listener->push($server);

        try {
            static::assertSame($server, $composite->accept());
        } finally {
            $client->close();
            $server->close();
            $composite->close();
        }
    }
}
