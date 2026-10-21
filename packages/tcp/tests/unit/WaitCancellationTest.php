<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\TCP;
use Psl\TCP\Tests\Fixture\DeferredCancellationToken;
use Psl\TCP\Tests\Fixture\ExpiringCancellationToken;
use Psl\TCP\Tests\Fixture\InlineCancellationToken;
use Revolt\EventLoop;

use function array_filter;
use function array_values;

/**
 * A waiting connect or accept settles once, and its cancellation listener reaches only that wait: not before the
 * wait has suspended, and not a later wait of the same fiber, which the event loop hands the same suspension.
 */
final class WaitCancellationTest extends TestCase
{
    /**
     * A deadline that passes between the connect's check and its subscribe fails the connect with the token's
     * exception.
     */
    public function testConnectFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        try {
            static::assertSame($token->exception, self::connectFailure($port, $token));
            static::assertSame($callbacks, self::enabledCallbacks());

            self::assertConnects($listener);
        } finally {
            $listener->close();
        }
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the connect with the token's exception.
     */
    public function testConnectFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        try {
            static::assertSame($token->exception, self::connectFailure($port, $token));
            static::assertSame($callbacks, self::enabledCallbacks());

            self::assertConnects($listener);
        } finally {
            $listener->close();
        }
    }

    /**
     * The listener of a connect that has returned, notified from a snapshot taken while it waited, does not end the
     * next wait of the same fiber.
     */
    public function testLateCancellationOfAnEarlierConnectDoesNotEndALaterWait(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $token = new DeferredCancellationToken();
        $connected = new Async\Deferred();

        try {
            $reader = Async\run(static function () use ($port, $token, $connected): string {
                $stream = TCP\connect('127.0.0.1', $port, cancellation: $token);
                $connected->complete(null);

                // A second wait of the same fiber, which does not use the token.
                return $stream->read();
            });

            // The reader waits in its connect, subscribed to the token. The token is cancelled, but its listeners
            // are notified only later, after the connect has returned.
            Async\later();
            $token->cancel();
            $connected->getAwaitable()->await();

            // The reader now waits in its read; the connect's listener must not end it.
            $token->deliver();
            $listener->accept()->writeAll('second');

            static::assertSame('second', $reader->await());
        } finally {
            $listener->close();
        }
    }

    /**
     * A deadline that passes between the accept's check and its subscribe fails the accept with the token's
     * exception.
     */
    public function testAcceptFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $token = new ExpiringCancellationToken();

        try {
            static::assertSame($token->exception, self::acceptFailure($listener, $token));

            self::assertConnects($listener);
        } finally {
            $listener->close();
        }
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the accept with the token's exception.
     */
    public function testAcceptFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $token = new InlineCancellationToken();

        try {
            static::assertSame($token->exception, self::acceptFailure($listener, $token));

            self::assertConnects($listener);
        } finally {
            $listener->close();
        }
    }

    /**
     * The listener of an accept that has returned, notified from a snapshot taken while it waited, does not end the
     * next accept of the same fiber.
     */
    public function testLateCancellationOfAnEarlierAcceptDoesNotEndALaterAccept(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $token = new DeferredCancellationToken();
        $firstAccept = new Async\Deferred();

        try {
            $acceptor = Async\run(static function () use ($listener, $token, $firstAccept): TCP\StreamInterface {
                $firstAccept->complete($listener->accept($token));

                // A second wait of the same fiber, which does not use the token.
                return $listener->accept();
            });

            // The acceptor waits in its first accept, subscribed to the token. The token is cancelled, but its
            // listeners are notified only later, after that accept has returned the connection made below.
            Async\later();
            $token->cancel();
            $first = TCP\connect('127.0.0.1', $port);
            static::assertSame(
                $first->getLocalAddress()->port,
                $firstAccept->getAwaitable()->await()->getPeerAddress()->port,
            );

            // The acceptor now waits in its second accept; the first accept's listener must not end it.
            $token->deliver();
            $second = TCP\connect('127.0.0.1', $port);

            static::assertSame($second->getLocalAddress()->port, $acceptor->await()->getPeerAddress()->port);
        } finally {
            $listener->close();
        }
    }

    /**
     * Connect with the given token, and return the cancellation it failed with.
     *
     * @param int<0, max> $port
     */
    private static function connectFailure(
        int $port,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            TCP\connect('127.0.0.1', $port, cancellation: $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The connect was not cancelled.');
    }

    /**
     * Accept with the given token, and return the cancellation it failed with.
     */
    private static function acceptFailure(
        TCP\ListenerInterface $listener,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $listener->accept($cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The accept was not cancelled.');
    }

    /**
     * Assert that a connection to the listener still goes through, and carries bytes.
     *
     * A connection that a cancelled connect gave up on may still be queued before it; that one ends without bytes.
     */
    private static function assertConnects(TCP\ListenerInterface $listener): void
    {
        $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port);
        $client->writeAll('hello');
        $client->shutdown();

        do {
            $server = $listener->accept();
            $received = $server->readAll();
            $server->close();
        } while ('' === $received);

        static::assertSame('hello', $received);

        $client->close();
    }

    /**
     * The event loop callbacks that are enabled.
     *
     * @return list<string>
     */
    private static function enabledCallbacks(): array
    {
        return array_values(array_filter(EventLoop::getIdentifiers(), EventLoop::isEnabled(...)));
    }
}
