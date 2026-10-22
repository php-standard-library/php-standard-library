<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\UDP;
use Revolt\EventLoop;

use function array_filter;
use function array_values;

/**
 * A waiting receive or send settles once, and its cancellation listener reaches only that wait: not before the wait
 * has suspended, and not a later wait of the same fiber, which the event loop hands the same suspension.
 */
final class WaitCancellationTest extends TestCase
{
    /**
     * The most bytes a receive in these tests takes.
     */
    private const int MAX_BYTES = 100;

    /**
     * A deadline that passes between the receive's check and its subscribe fails the receive with the token's
     * exception.
     */
    public function testReceiveFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        try {
            static::assertSame($token->exception, self::receiveFailure($socket, $token));
            static::assertSame($callbacks, self::enabledCallbacks());

            self::assertReceives($socket);
        } finally {
            $socket->close();
        }
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the receive with the token's exception.
     */
    public function testReceiveFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        try {
            static::assertSame($token->exception, self::receiveFailure($socket, $token));
            static::assertSame($callbacks, self::enabledCallbacks());

            self::assertReceives($socket);
        } finally {
            $socket->close();
        }
    }

    /**
     * The listener of a receive that has returned, notified from a snapshot taken while it waited, does not end the
     * next receive of the same fiber.
     */
    public function testLateCancellationOfAnEarlierReceiveDoesNotEndALaterReceive(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1');
        $sender = UDP\Socket::bind('127.0.0.1');
        $token = new DeferredCancellationToken();
        $firstReceive = new Async\Deferred();

        try {
            $receiver = Async\run(static function () use ($socket, $token, $firstReceive): string {
                $firstReceive->complete($socket->receiveFrom(self::MAX_BYTES, $token)[0]);

                // A second wait of the same fiber, which does not use the token.
                return $socket->receiveFrom(self::MAX_BYTES)[0];
            });

            // The receiver waits in its first receive, subscribed to the token. The token is cancelled, but its
            // listeners are notified only later, after that receive has returned the datagram sent below.
            Async\later();
            $token->cancel();
            $sender->sendTo('first', $socket->getLocalAddress());
            static::assertSame('first', $firstReceive->getAwaitable()->await());

            // The receiver now waits in its second receive; the first receive's listener must not end it.
            $token->deliver();
            $sender->sendTo('second', $socket->getLocalAddress());

            static::assertSame('second', $receiver->await());
        } finally {
            $socket->close();
            $sender->close();
        }
    }

    /**
     * A deadline that passes between the send's check and its subscribe fails the send with the token's exception.
     */
    public function testSendFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        try {
            static::assertSame($token->exception, self::sendFailure($client, $token));
            static::assertSame($callbacks, self::enabledCallbacks());

            $client->send('hello');
            static::assertSame('hello', $server->receiveFrom(self::MAX_BYTES)[0]);
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the send with the token's exception.
     */
    public function testSendFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        try {
            static::assertSame($token->exception, self::sendFailure($client, $token));
            static::assertSame($callbacks, self::enabledCallbacks());

            $client->send('hello');
            static::assertSame('hello', $server->receiveFrom(self::MAX_BYTES)[0]);
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * The listener of a send that has returned, notified from a snapshot taken while it waited, does not end the
     * next wait of the same fiber.
     */
    public function testLateCancellationOfAnEarlierSendDoesNotEndALaterWait(): void
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $client = UDP\connect('127.0.0.1', $server->getLocalAddress()->port);
        $token = new DeferredCancellationToken();
        $sent = new Async\Deferred();

        try {
            $sender = Async\run(static function () use ($client, $token, $sent): string {
                $client->send('first', $token);
                $sent->complete(null);

                // A second wait of the same fiber, which does not use the token.
                return $client->receive(self::MAX_BYTES);
            });

            // The sender waits for its socket to become writable, subscribed to the token. The token is cancelled,
            // but its listeners are notified only later, after the send has returned.
            Async\later();
            $token->cancel();
            $sent->getAwaitable()->await();
            [$first, $from] = $server->receiveFrom(self::MAX_BYTES);
            static::assertSame('first', $first);

            // The sender now waits in its receive; the send's listener must not end it.
            $token->deliver();
            $server->sendTo('second', $from);

            static::assertSame('second', $sender->await());
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * Receive with the given token, and return the cancellation it failed with.
     */
    private static function receiveFailure(
        UDP\Socket $socket,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $socket->receiveFrom(self::MAX_BYTES, $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The receive was not cancelled.');
    }

    /**
     * Send with the given token, and return the cancellation it failed with.
     */
    private static function sendFailure(
        UDP\ConnectedSocket $socket,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $socket->send('cancelled', $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The send was not cancelled.');
    }

    /**
     * Assert that the socket still receives a datagram sent to it.
     */
    private static function assertReceives(UDP\Socket $socket): void
    {
        $sender = UDP\Socket::bind('127.0.0.1');
        $sender->sendTo('hello', $socket->getLocalAddress());

        static::assertSame('hello', $socket->receiveFrom(self::MAX_BYTES)[0]);

        $sender->close();
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
