<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Network;
use Psl\Network\Tests\Fixture\DeferredCancellationToken;
use Psl\Network\Tests\Fixture\ExpiringCancellationToken;
use Psl\Network\Tests\Fixture\InlineCancellationToken;
use Revolt\EventLoop;

use function array_filter;
use function array_values;

/**
 * A waiting peek settles once, and its cancellation listener reaches only that wait: not before the wait has
 * suspended, and not a later wait of the same fiber, which the event loop hands the same suspension.
 */
final class PeekCancellationTest extends TestCase
{
    /**
     * A deadline that passes between the peek's check and its subscribe fails the peek with the token's exception.
     */
    public function testPeekFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        [$a, $b] = Network\socket_pair();
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        $failure = self::peekFailure($a, $token);

        static::assertSame($token->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        $b->writeAll('hello');
        static::assertSame('hello', $a->peek(5));
        static::assertSame('hello', $a->read());
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the peek with the token's exception.
     */
    public function testPeekFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        [$a, $b] = Network\socket_pair();
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        $failure = self::peekFailure($a, $token);

        static::assertSame($token->exception, $failure);
        static::assertSame($callbacks, self::enabledCallbacks());

        $b->writeAll('hello');
        static::assertSame('hello', $a->peek(5));
        static::assertSame('hello', $a->read());
    }

    /**
     * The listener of a peek that has returned, notified from a snapshot taken while it waited, does not end the
     * next peek of the same fiber.
     */
    public function testLateCancellationOfAnEarlierPeekDoesNotEndALaterPeek(): void
    {
        [$a, $b] = Network\socket_pair();
        $token = new DeferredCancellationToken();
        $firstPeek = new Async\Deferred();

        $peeker = Async\run(static function () use ($a, $token, $firstPeek): string {
            $first = $a->peek(5, $token);
            static::assertSame($first, $a->read(5));
            $firstPeek->complete($first);

            // A second wait of the same fiber, which does not use the token.
            return $a->peek(6);
        });

        // The peeker waits in its first peek, subscribed to the token. The token is cancelled, but its listeners
        // are notified only later, after that peek has returned the bytes written below.
        Async\later();
        $token->cancel();
        $b->writeAll('first');
        static::assertSame('first', $firstPeek->getAwaitable()->await());

        // The peeker now waits in its second peek; the first peek's listener must not end it.
        $token->deliver();
        $b->writeAll('second');

        static::assertSame('second', $peeker->await());
    }

    /**
     * Peek with the given token, and return the cancellation it failed with.
     */
    private static function peekFailure(
        Network\StreamInterface $stream,
        Async\CancellationTokenInterface $cancellation,
    ): Async\Exception\CancelledException {
        try {
            $stream->peek(5, $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The peek was not cancelled.');
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
