<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TCP\Tests\Fixture\CallbackSnapshot;
use Psl\TCP\Tests\Fixture\MemoryConnector;
use Psl\TCP\Tests\Fixture\RecordingDriver;
use Revolt\EventLoop;

use function fclose;
use function fopen;
use function spl_object_id;

final class SocketPoolTest extends TestCase
{
    public function testIpv6SpellingsShareThePoolKey(): void
    {
        $resource = fopen('php://memory', 'r+');
        static::assertIsResource($resource);
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('getStream')->willReturn($resource);
        $connector = $this->createMock(TCP\ConnectorInterface::class);
        $connector->expects(static::once())->method('connect')->with('::1', 1234)->willReturn($stream);
        $pool = new TCP\SocketPool($connector);

        try {
            $first = $pool->checkout('::1', 1234);
            $pool->checkin($first);
            static::assertSame($first, $pool->checkout('[::1]', 1234));
        } finally {
            $pool->close();
            fclose($resource);
        }
    }

    /**
     * The idle timer must go through {@see Async\Scheduler}, so that scheduler-level handling of long timers covers
     * it. Swapping in a recording driver needs an event loop that has not run yet, hence the separate process.
     */
    #[RunInSeparateProcess]
    public function testIdleTimerIsScheduledThroughTheAsyncScheduler(): void
    {
        $driver = new RecordingDriver(EventLoop::getDriver());
        EventLoop::setDriver($driver);

        $resource = fopen('php://memory', 'r+');
        static::assertIsResource($resource);
        $stream = $this->createStub(TCP\StreamInterface::class);
        $stream->method('getStream')->willReturn($resource);
        $connector = $this->createStub(TCP\ConnectorInterface::class);
        $connector->method('connect')->willReturn($stream);
        $pool = new TCP\SocketPool($connector, Duration::seconds(7));

        try {
            $pool->checkin($pool->checkout('127.0.0.1', 1234));

            static::assertCount(1, $driver->timers);
            static::assertSame(Async\Scheduler::class . '::delay', $driver->timers[0]['registeredBy']);
            static::assertSame(7.0, $driver->timers[0]['seconds']);
            // Like before, an idle connection keeps the event loop running until its timer fires.
            static::assertTrue(EventLoop::isReferenced($driver->timers[0]['id']));
        } finally {
            $pool->close();
            fclose($resource);
        }
    }

    public function testCheckoutCreatesNewConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('pool-test', $data);
                $connection->writeAll('pool-ok');
                $connection->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();
                $stream = $pool->checkout('127.0.0.1', $port);
                $stream->writeAll('pool-test');
                $response = $stream->readAll();
                self::assertSame('pool-ok', $response);
                $pool->close();
            },
        ]);
    }

    public function testCheckinAndReuseConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                // Accept first use
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('first', $data);
                $connection->writeAll('first-ok');

                // The same connection will be reused, read again
                $data2 = $connection->read();
                self::assertSame('second', $data2);
                $connection->writeAll('second-ok');
                $connection->close();

                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                // First checkout
                $stream = $pool->checkout('127.0.0.1', $port);
                $stream->writeAll('first');
                $response = $stream->read();
                self::assertSame('first-ok', $response);

                // Return to pool
                $pool->checkin($stream);

                // Second checkout should reuse the same connection
                $stream2 = $pool->checkout('127.0.0.1', $port);
                $stream2->writeAll('second');
                $response2 = $stream2->read();
                self::assertSame('second-ok', $response2);

                $pool->clear($stream2);
                $pool->close();
            },
        ]);
    }

    public function testClearRemovesFromPool(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                // First connection
                $connection1 = $listener->accept();
                $data1 = $connection1->read();
                self::assertSame('conn1', $data1);
                $connection1->writeAll('ok1');
                $connection1->close();

                // Second connection (new, because first was cleared)
                $connection2 = $listener->accept();
                $data2 = $connection2->read();
                self::assertSame('conn2', $data2);
                $connection2->writeAll('ok2');
                $connection2->close();

                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                $stream1 = $pool->checkout('127.0.0.1', $port);
                $stream1->writeAll('conn1');
                $stream1->read();

                $pool->clear($stream1);

                $stream2 = $pool->checkout('127.0.0.1', $port);
                $stream2->writeAll('conn2');
                $response = $stream2->readAll();
                self::assertSame('ok2', $response);

                $pool->close();
            },
        ]);
    }

    public function testIsClosedReflectsState(): void
    {
        $pool = new TCP\SocketPool();

        static::assertFalse($pool->isClosed());
        $pool->close();
        static::assertTrue($pool->isClosed());
    }

    public function testCheckinUnknownStreamIsIgnored(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $connection = $listener->accept();
                $connection->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                $stream = TCP\connect('127.0.0.1', $port);

                $pool->checkin($stream);

                $resource = $stream->getStream();
                self::assertNotNull($resource);

                $stream->close();
                $pool->close();
            },
        ]);
    }

    public function testCloseClosesAllIdleConnections(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('x', $data);
                $connection->writeAll('a');

                $remaining = $connection->readAll();
                self::assertSame('', $remaining);
                $connection->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                $stream = $pool->checkout('127.0.0.1', $port);
                $stream->writeAll('x');
                $stream->read();
                $pool->checkin($stream);

                $pool->close();
            },
        ]);
    }

    public function testCustomConnector(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('custom', $data);
                $connection->writeAll('ok');
                $connection->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $connector = new TCP\StaticConnector('127.0.0.1', $port);
                $pool = new TCP\SocketPool(connector: $connector);

                $stream = $pool->checkout('anything', 9999);
                $stream->writeAll('custom');
                $response = $stream->readAll();
                self::assertSame('ok', $response);

                $pool->close();
            },
        ]);
    }

    public function testCheckoutSkipsDeadIdleConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port ?? 0;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $conn1 = $listener->accept();
                $conn1->read();
                $conn1->writeAll('first-ok');
                $conn1->close();

                $conn2 = $listener->accept();
                $conn2->read();
                $conn2->writeAll('second-ok');
                $conn2->close();

                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                $stream1 = $pool->checkout('127.0.0.1', $port);
                $stream1->writeAll('first');
                $stream1->read();
                $pool->checkin($stream1);

                $stream1->close();

                $stream2 = $pool->checkout('127.0.0.1', $port);
                $stream2->writeAll('second');
                $response = $stream2->readAll();
                self::assertSame('second-ok', $response);

                $pool->close();
            },
        ]);
    }

    public function testCheckinClosedStreamIsDiscarded(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port ?? 0;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $conn1 = $listener->accept();
                $conn1->read();
                $conn1->writeAll('ok');
                $conn1->close();

                $conn2 = $listener->accept();
                $conn2->read();
                $conn2->writeAll('fresh');
                $conn2->close();

                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                $stream = $pool->checkout('127.0.0.1', $port);
                $stream->writeAll('hello');
                $stream->read();

                $stream->close();
                $pool->checkin($stream);

                $stream2 = $pool->checkout('127.0.0.1', $port);
                $stream2->writeAll('new');
                $response = $stream2->readAll();
                self::assertSame('fresh', $response);

                $pool->close();
            },
        ]);
    }

    public function testIdleTimeoutClosesConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port ?? 0;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $conn1 = $listener->accept();
                $conn1->read();
                $conn1->writeAll('ok');
                $conn1->readAll();
                $conn1->close();

                $conn2 = $listener->accept();
                $conn2->read();
                $conn2->writeAll('after-timeout');
                $conn2->close();

                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool(idleTimeout: Duration::milliseconds(50));

                $stream = $pool->checkout('127.0.0.1', $port);
                $stream->writeAll('ping');
                $stream->read();
                $pool->checkin($stream);

                Async\sleep(Duration::milliseconds(100));

                $stream2 = $pool->checkout('127.0.0.1', $port);
                $stream2->writeAll('ping2');
                $response = $stream2->readAll();
                self::assertSame('after-timeout', $response);

                $pool->close();
            },
        ]);
    }

    public function testClearIdleConnectionRemovesFromPool(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port ?? 0;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $conn = $listener->accept();
                $conn->read();
                $conn->writeAll('ok');
                $conn->readAll();
                $conn->close();

                $conn2 = $listener->accept();
                $conn2->read();
                $conn2->writeAll('new');
                $conn2->close();

                $listener->close();
            },
            'client' => static function () use ($port): void {
                $pool = new TCP\SocketPool();

                $stream = $pool->checkout('127.0.0.1', $port);
                $stream->writeAll('ping');
                $stream->read();
                $pool->checkin($stream);
                $pool->clear($stream);

                $stream2 = $pool->checkout('127.0.0.1', $port);
                $stream2->writeAll('ping2');
                $response = $stream2->readAll();
                self::assertSame('new', $response);

                $pool->close();
            },
        ]);
    }

    /**
     * A connection that its borrower drops without checking it in leaves the pool's books. A connection made later
     * that gets the same object ID is foreign to the pool: checking it in does nothing, and a checkout for the
     * dropped connection's host and port does not hand it out.
     */
    public function testForeignConnectionWithTheObjectIdOfADroppedOneIsNotPooled(): void
    {
        $connector = new MemoryConnector();
        $pool = new TCP\SocketPool($connector);

        // Everything the foreign connection needs is made before the checked-out connection is dropped, so that the
        // foreign connection is the next object PHP creates, and takes the dropped connection's object ID.
        $localAddress = Network\Address::tcp('127.0.0.1', 0);
        $peerAddress = Network\Address::tcp('192.0.2.1', 443);
        $resource = fopen('php://memory', 'r+');
        static::assertIsResource($resource);

        $dropped = $pool->checkout('example.com', 80);
        $id = spl_object_id($dropped);
        unset($dropped);

        $foreign = new TCP\Internal\Stream($resource, $localAddress, $peerAddress);
        static::assertSame($id, spl_object_id($foreign), 'The foreign connection must reuse the object ID.');

        try {
            $pool->checkin($foreign);

            $connection = $pool->checkout('example.com', 80);
            static::assertNotSame($foreign, $connection);
            static::assertSame(2, $connector->connects);
            static::assertFalse($foreign->isClosed());
        } finally {
            $foreign->close();
            $pool->close();
        }
    }

    /**
     * Clearing an idle connection takes it out of the pool and cancels its idle timer: once the event loop has
     * warmed up, a check-in and a clear leave no callback behind, and the next checkout opens a new connection.
     */
    public function testClearOfAnIdleConnectionCancelsItsIdleTimer(): void
    {
        $connector = new MemoryConnector();
        $pool = new TCP\SocketPool($connector);

        try {
            self::checkInAndClear($pool);
            $before = new CallbackSnapshot();

            $connection = self::checkInAndClear($pool);

            $after = new CallbackSnapshot();
            static::assertSame([], $after->addedSince($before));
            static::assertSame([], $after->grownSince($before));
            static::assertTrue($connection->isClosed());

            static::assertNotSame($connection, $pool->checkout('example.com', 80));
            static::assertSame(3, $connector->connects);
        } finally {
            $pool->close();
        }
    }

    /**
     * Check a connection out of the pool, check it in, then clear it while it is idle.
     *
     * @return TCP\StreamInterface The cleared connection.
     */
    private static function checkInAndClear(TCP\SocketPool $pool): TCP\StreamInterface
    {
        $connection = $pool->checkout('example.com', 80);
        $pool->checkin($connection);
        $pool->clear($connection);

        return $connection;
    }
}
