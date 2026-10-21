<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\Network\Exception\AlreadyStoppedException;
use Psl\TCP;
use Revolt\EventLoop;
use WeakReference;

use function array_diff;
use function array_values;
use function extension_loaded;
use function fclose;
use function gc_collect_cycles;
use function socket_import_stream;
use function socket_set_option;
use function stream_socket_client;

use const SO_LINGER;
use const SOL_SOCKET;

final class ServerTest extends TestCase
{
    public function testResetBufferedConnectionKeepsCapturedPeer(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required.');
        }

        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;

        try {
            $warmup = TCP\connect('127.0.0.1', $port);
            $listener->accept()->close();
            $warmup->close();

            $client = stream_socket_client("tcp://127.0.0.1:{$port}");
            static::assertIsResource($client);
            $socket = socket_import_stream($client);
            static::assertNotFalse($socket);
            static::assertTrue(socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]));
            Async\sleep(Duration::milliseconds(50));
            fclose($client);
            unset($socket);
            Async\sleep(Duration::milliseconds(50));

            $connection = $listener->accept(new Async\TimeoutCancellationToken(Duration::seconds(1)));
            static::assertSame('127.0.0.1', $connection->getPeerAddress()->host);
            $connection->close();
            static::assertFalse($listener->isClosed());

            $next = TCP\connect('127.0.0.1', $port);
            $listener->accept()->close();
            $next->close();
        } finally {
            $listener->close();
        }
    }

    public function testAcceptOnStoppedListener(): void
    {
        $listener = TCP\listen(
            '127.0.0.1',
            0,
            new TCP\ListenConfiguration(noDelay: true, reuseAddress: false, reusePort: false),
        );

        $listener->close();

        $this->expectException(AlreadyStoppedException::class);
        $this->expectExceptionMessage('Server socket has already been stopped.');

        $listener->accept();
    }

    public function testGetLocalAddressOnStoppedListener(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $address = $listener->getLocalAddress();
        $listener->close();

        // getLocalAddress() returns the cached address even after the listener is stopped.
        $addressAfterClose = $listener->getLocalAddress();
        static::assertSame($address->host, $addressAfterClose->host);
        static::assertSame($address->port, $addressAfterClose->port);
    }

    public function testListenWithCustomBacklog(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(backlog: 128));
        $address = $listener->getLocalAddress();

        static::assertSame('127.0.0.1', $address->host);
        static::assertGreaterThan(0, $address->port);

        $client = TCP\connect('127.0.0.1', $address->port);
        $server = $listener->accept();

        $client->write('ping');
        static::assertSame('ping', $server->read(4));

        $client->close();
        $server->close();
        $listener->close();
    }

    public function testAcceptMultipleConnections(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true, backlog: 64));
        $address = $listener->getLocalAddress();

        [$server1, $client1, $client2] = Async\concurrently([
            $listener->accept(...),
            static fn(): Network\StreamInterface => TCP\connect('127.0.0.1', $address->port),
            static fn(): Network\StreamInterface => TCP\connect('127.0.0.1', $address->port),
        ]);

        $server2 = $listener->accept();

        $client1->write('one');
        $client2->write('two');

        $msg1 = $server1->read(3);
        $msg2 = $server2->read(3);

        static::assertTrue($msg1 === 'one' && $msg2 === 'two' || $msg1 === 'two' && $msg2 === 'one');

        $client1->close();
        $client2->close();
        $server1->close();
        $server2->close();
        $listener->close();
    }

    public function testWaitsForPendingOperation(): void
    {
        $listener = TCP\listen('127.0.0.1');

        $first = Async\run($listener->accept(...));

        [$second_connection, $client_one, $client_two] = Async\concurrently([
            $listener->accept(...),
            static fn(): Network\StreamInterface => TCP\connect('127.0.0.1', $listener->getLocalAddress()->port),
            static fn(): Network\StreamInterface => TCP\connect('127.0.0.1', $listener->getLocalAddress()->port),
        ]);

        static::assertTrue($first->isComplete());
        $firstConnection = $first->await();

        $client_two->write('hello');
        $pocket = $second_connection->read(5);

        static::assertSame('hello', $pocket);

        $client_one->close();
        $client_two->close();
        $firstConnection->close();
        $second_connection->close();

        $listener->close();
    }

    public function testListenerIsDestroyedOnceNoLongerReferenced(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $reference = WeakReference::create($listener);
        unset($listener);

        static::assertNull($reference->get());
    }

    public function testAcceptingListenerIsDestroyedOnceNoLongerReferenced(): void
    {
        $callbacks = self::getReferencedCallbacks();

        $listener = TCP\listen('127.0.0.1');
        $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port);
        $listener->accept()->close();
        $client->close();

        // accept() enabled the listener's watcher. If the event loop kept the listener alive, the listening
        // socket would never be closed, and the watcher would keep the event loop running forever.
        $reference = WeakReference::create($listener);
        unset($listener);

        static::assertNull($reference->get());
        static::assertSame([], array_values(array_diff(self::getReferencedCallbacks(), $callbacks)));
    }

    public function testWaitingAcceptKeepsListenerAlive(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $accept = Async\run($listener->accept(...));
        unset($listener);

        // Once accept() waits, the listener is only referenced by the waiting fiber, which is itself only
        // referenced by the listener. The event loop must keep both alive until a connection comes in.
        Async\later();
        gc_collect_cycles();

        $client = TCP\connect('127.0.0.1', $port);
        $server = $accept->await();

        $client->write('ping');
        static::assertSame('ping', $server->read(4));

        $client->close();
        $server->close();
    }

    /**
     * @return list<string>
     */
    private static function getReferencedCallbacks(): array
    {
        $driver = EventLoop::getDriver();
        $callbacks = [];
        foreach ($driver->getIdentifiers() as $callback) {
            if (!$driver->isEnabled($callback) || !$driver->isReferenced($callback)) {
                continue;
            }

            $callbacks[] = $callback;
        }

        return $callbacks;
    }
}
