<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use Override;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Network;
use Psl\OS;
use Psl\Unix;
use Revolt\EventLoop;

use function array_filter;
use function array_values;
use function bin2hex;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * A waiting peek or accept settles once, and its cancellation listener reaches only that wait: not before the wait
 * has suspended, and not a later wait of the same fiber, which the event loop hands the same suspension.
 */
final class WaitCancellationTest extends TestCase
{
    /**
     * The number of random bytes in each socket path, which appear hex-encoded.
     */
    private const int NAME_BYTES = 8;

    /**
     * How many accept() calls wait at once in the close test.
     */
    private const int WAITING_ACCEPTS = 3;

    /**
     * The path of the listener the test uses.
     *
     * @var non-empty-string
     */
    private string $path;

    /**
     * The listener the test uses.
     */
    private Unix\ListenerInterface $listener;

    /**
     * Listen on a fresh path.
     */
    #[Override]
    protected function setUp(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix sockets are not supported on Windows platform.');
        }

        $this->path = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(self::NAME_BYTES)) . '.sock';
        $this->listener = Unix\listen($this->path);
    }

    /**
     * Close the listener, and remove its path.
     */
    #[Override]
    protected function tearDown(): void
    {
        if (OS\is_windows()) {
            return;
        }

        $this->listener->close();
        @unlink($this->path);
    }

    /**
     * A deadline that passes between the peek's check and its subscribe fails the peek with the token's exception.
     */
    public function testPeekFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        [$server, $client] = $this->connect();
        $token = new ExpiringCancellationToken();
        $callbacks = self::enabledCallbacks();

        static::assertSame($token->exception, self::peekFailure($server, $token));
        static::assertSame($callbacks, self::enabledCallbacks());

        $client->writeAll('hello');
        static::assertSame('hello', $server->peek(5));
        static::assertSame('hello', $server->readFixedSize(5));
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the peek with the token's exception.
     */
    public function testPeekFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        [$server, $client] = $this->connect();
        $token = new InlineCancellationToken();
        $callbacks = self::enabledCallbacks();

        static::assertSame($token->exception, self::peekFailure($server, $token));
        static::assertSame($callbacks, self::enabledCallbacks());

        $client->writeAll('hello');
        static::assertSame('hello', $server->peek(5));
        static::assertSame('hello', $server->readFixedSize(5));
    }

    /**
     * The listener of a peek that has returned, notified from a snapshot taken while it waited, does not end the
     * next peek of the same fiber.
     */
    public function testLateCancellationOfAnEarlierPeekDoesNotEndALaterPeek(): void
    {
        [$server, $client] = $this->connect();
        $token = new DeferredCancellationToken();
        $firstPeek = new Async\Deferred();

        $peeker = Async\run(static function () use ($server, $token, $firstPeek): string {
            $first = $server->peek(5, $token);
            static::assertSame($first, $server->readFixedSize(5));
            $firstPeek->complete($first);

            // A second wait of the same fiber, which does not use the token.
            return $server->peek(6);
        });

        // The peeker waits in its first peek, subscribed to the token. The token is cancelled, but its listeners are
        // notified only later, after that peek has returned the bytes written below.
        Async\later();
        $token->cancel();
        $client->writeAll('first');
        static::assertSame('first', $firstPeek->getAwaitable()->await());

        // The peeker now waits in its second peek; the first peek's listener must not end it.
        $token->deliver();
        $client->writeAll('second');

        static::assertSame('second', $peeker->await());
    }

    /**
     * A deadline that passes between the accept's check and its subscribe fails the accept with the token's
     * exception.
     */
    public function testAcceptFailsWhenTheDeadlinePassesBeforeSubscribing(): void
    {
        $token = new ExpiringCancellationToken();

        static::assertSame($token->exception, $this->acceptFailure($token));

        $this->assertConnects();
    }

    /**
     * A token that notifies its listener inside `subscribe()` fails the accept with the token's exception.
     */
    public function testAcceptFailsWhenTheTokenNotifiesDuringSubscribe(): void
    {
        $token = new InlineCancellationToken();

        static::assertSame($token->exception, $this->acceptFailure($token));

        $this->assertConnects();
    }

    /**
     * The listener of an accept that has returned, notified from a snapshot taken while it waited, does not end the
     * next accept of the same fiber.
     */
    public function testLateCancellationOfAnEarlierAcceptDoesNotEndALaterAccept(): void
    {
        $listener = $this->listener;
        $token = new DeferredCancellationToken();
        $firstAccept = new Async\Deferred();

        $acceptor = Async\run(static function () use ($listener, $token, $firstAccept): Unix\StreamInterface {
            $firstAccept->complete($listener->accept($token));

            // A second wait of the same fiber, which does not use the token.
            return $listener->accept();
        });

        // The acceptor waits in its first accept, subscribed to the token. The token is cancelled, but its listeners
        // are notified only later, after that accept has returned the connection made below.
        Async\later();
        $token->cancel();
        $first = Unix\connect($this->path);
        $first->writeAll('first');
        static::assertSame('first', $firstAccept->getAwaitable()->await()->readFixedSize(5));

        // The acceptor now waits in its second accept; the first accept's listener must not end it.
        $token->deliver();
        $second = Unix\connect($this->path);
        $second->writeAll('second');

        static::assertSame('second', $acceptor->await()->readFixedSize(6));
    }

    /**
     * Closing the listener fails every waiting accept with `AlreadyStoppedException`.
     */
    public function testCloseFailsEveryWaitingAccept(): void
    {
        $accepts = [];
        for ($i = 0; $i < self::WAITING_ACCEPTS; $i++) {
            $accepts[] = Async\run($this->listener->accept(...));
        }

        Async\later();
        $this->listener->close();

        foreach ($accepts as $accept) {
            $failure = null;
            try {
                $accept->await();
            } catch (Network\Exception\AlreadyStoppedException $e) {
                $failure = $e;
            }

            static::assertInstanceOf(Network\Exception\AlreadyStoppedException::class, $failure);
        }
    }

    /**
     * A connection handed out while a cancelled accept is still queued, because its fiber has not run again yet, goes
     * to the next waiting accept instead of being lost.
     */
    public function testConnectionSkipsAnAcceptThatACancellationEnded(): void
    {
        $listener = $this->listener;
        $token = new Async\SignalCancellationToken();

        $cancelled = Async\run(static fn(): Unix\StreamInterface => $listener->accept($token));
        $waiting = Async\run($listener->accept(...));
        Async\later();

        // The connection waits in the kernel until close() accepts it, after the cancellation and before either
        // fiber runs again.
        $client = Unix\connect($this->path);
        $token->cancel();
        $listener->close();

        $failure = null;
        try {
            $cancelled->await();
        } catch (Async\Exception\CancelledException $e) {
            $failure = $e;
        }

        static::assertInstanceOf(Async\Exception\CancelledException::class, $failure);

        $server = $waiting->await();
        $client->writeAll('hello');
        static::assertSame('hello', $server->readFixedSize(5));

        $server->close();
        $client->close();
    }

    /**
     * Connect to the listener, and return the accepted stream and the client stream.
     *
     * @return array{Unix\StreamInterface, Unix\StreamInterface}
     */
    private function connect(): array
    {
        $client = Unix\connect($this->path);

        return [$this->listener->accept(), $client];
    }

    /**
     * Accept with the given token, and return the cancellation it failed with.
     */
    private function acceptFailure(Async\CancellationTokenInterface $cancellation): Async\Exception\CancelledException
    {
        try {
            $this->listener->accept($cancellation);
        } catch (Async\Exception\CancelledException $e) {
            return $e;
        }

        static::fail('The accept was not cancelled.');
    }

    /**
     * Assert that a connection to the listener still goes through, and carries bytes.
     */
    private function assertConnects(): void
    {
        $client = Unix\connect($this->path);
        $client->writeAll('hello');

        $server = $this->listener->accept();
        static::assertSame('hello', $server->readFixedSize(5));

        $server->close();
        $client->close();
    }

    /**
     * Peek with the given token, and return the cancellation it failed with.
     */
    private static function peekFailure(
        Unix\StreamInterface $stream,
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
