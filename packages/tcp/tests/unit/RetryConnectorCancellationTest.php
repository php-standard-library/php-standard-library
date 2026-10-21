<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use Override;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Revolt\EventLoop;

use function array_filter;
use function array_values;

/**
 * A backoff wait of `TCP\RetryConnector::connect()` settles once, and its cancellation listener reaches only that
 * wait: not before the wait has suspended, and not a later wait of the same fiber, which the event loop hands the
 * same suspension.
 */
final class RetryConnectorCancellationTest extends TestCase
{
    /**
     * The backoff before the second attempt, in milliseconds.
     */
    private const int BACKOFF_MILLISECONDS = 10;

    /**
     * A deadline that passes between the backoff's check and its subscribe fails the connect with the token's
     * exception, and leaves no backoff timer behind.
     */
    public function testConnectFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        static::assertSame($token->exception, self::connectFailure($token));
        static::assertSame($callbacks, self::enabledCallbacks());
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the connect with the token's exception, and
     * leaves no backoff timer behind.
     */
    public function testConnectFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        static::assertSame($token->exception, self::connectFailure($token));
        static::assertSame($callbacks, self::enabledCallbacks());
    }

    /**
     * The listener of a backoff that has ended, notified from a snapshot taken while it waited, does not end the
     * next backoff of the same fiber.
     */
    public function testLateCancellationOfAnEarlierBackoffDoesNotEndALaterBackoff(): void
    {
        $token = new DeferredCancellationToken();
        $connected = new Async\Deferred();
        $first = self::connector();
        $second = self::connector();

        $connecting = Async\run(static function () use ($first, $second, $token, $connected): TCP\StreamInterface {
            $connected->complete($first->connect('127.0.0.1', 1, $token));

            // A second wait of the same fiber, which does not use the token.
            return $second->connect('127.0.0.1', 1);
        });

        // The fiber waits in its first backoff, subscribed to the token. The token is cancelled, but its listeners
        // are notified only later, after that backoff has ended and the connect has returned.
        Async\later();
        $token->cancel();
        $connected->getAwaitable()->await();

        // The fiber now waits in its second backoff; the first backoff's listener must not end it.
        $token->deliver();

        static::assertInstanceOf(TCP\StreamInterface::class, $connecting->await());
    }

    /**
     * Connect with the given token through a connector whose first attempt fails, and return the cancellation the
     * connect failed with.
     */
    private static function connectFailure(CancellationTokenInterface $cancellation): Async\Exception\CancelledException
    {
        try {
            self::connector()->connect('127.0.0.1', 1, $cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The connect was not cancelled.');
    }

    /**
     * A retry connector whose first attempt fails and whose second attempt succeeds.
     */
    private static function connector(): TCP\RetryConnector
    {
        $failingOnce = new class(static::createStub(TCP\StreamInterface::class)) implements TCP\ConnectorInterface {
            /**
             * Whether an attempt has already failed.
             */
            private bool $failed = false;

            /**
             * Create a connector that returns the given stream once its first attempt has failed.
             */
            public function __construct(
                /**
                 * The stream every attempt after the first returns.
                 */
                private readonly TCP\StreamInterface $stream,
            ) {}

            /**
             * Fail the first attempt, and return the stream from every later one.
             */
            #[Override]
            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                if (!$this->failed) {
                    $this->failed = true;

                    throw new Network\Exception\RuntimeException('Connection refused.');
                }

                return $this->stream;
            }
        };

        return new TCP\RetryConnector($failingOnce, backoff: Duration::milliseconds(self::BACKOFF_MILLISECONDS));
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
