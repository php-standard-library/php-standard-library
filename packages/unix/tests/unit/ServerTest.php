<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Filesystem;
use Psl\Network;
use Psl\Network\Exception;
use Psl\OS;
use Psl\Unix;
use Revolt\EventLoop;
use WeakReference;

use function array_diff;
use function array_values;
use function bin2hex;
use function gc_collect_cycles;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

final class ServerTest extends TestCase
{
    public function testAcceptOnStoppedListener(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = Filesystem\create_temporary_file(prefix: 'psl-examples') . '.sock';
        $listener = Unix\listen($sock);
        $listener->close();

        $this->expectException(Exception\AlreadyStoppedException::class);
        $this->expectExceptionMessage('Server socket has already been stopped.');

        $listener->accept();
    }

    public function testListenRejectsPathContainingNullByte(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $file = Filesystem\create_temporary_file(prefix: 'psl-examples');
        $sock = $file . '.sock';

        try {
            Unix\listen($sock . "\0suffix");

            static::fail('Expected an InvalidArgumentException to be thrown.');
        } catch (Exception\InvalidArgumentException $exception) {
            static::assertSame('Unix socket path must not contain NULL bytes.', $exception->getMessage());
        } finally {
            Filesystem\delete_file($file);
            // Without the check, the path is cut at the NUL byte and a socket is bound at $sock.
            $bound = Filesystem\exists($sock);
            if ($bound) {
                unlink($sock);
            }
        }

        static::assertFalse($bound);
    }

    public function testListenOnAbstractSocket(): void
    {
        if (OS\OperatingSystemFamily::Linux !== OS\family()) {
            static::markTestSkipped('The abstract socket namespace is only supported on Linux.');
        }

        $name = "\0psl-unix-test-" . bin2hex(random_bytes(8));
        // Without the fallback to the given path, this throws, as PHP cannot read the socket's name.
        $listener = Unix\listen($name);

        try {
            static::assertSame($name, $listener->getLocalAddress()->host);

            [$server, $client] = Async\concurrently([
                $listener->accept(...),
                static fn(): Unix\StreamInterface => Unix\connect($name),
            ]);

            static::assertSame($name, $server->getLocalAddress()->host);

            $client->writeAll('ping');
            static::assertSame('ping', $server->readFixedSize(4));

            $client->close();
            $server->close();
        } finally {
            $listener->close();
        }
    }

    public function testGetLocalAddressOnStoppedListener(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = Filesystem\create_temporary_file(prefix: 'psl-examples') . '.sock';
        $listener = Unix\listen($sock);
        $address = $listener->getLocalAddress();
        $listener->close();

        // getLocalAddress() returns the cached address even after the listener is stopped.
        $addressAfterClose = $listener->getLocalAddress();
        static::assertSame($address->host, $addressAfterClose->host);
    }

    public function testWaitsForPendingOperation(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = Filesystem\create_temporary_file(prefix: 'psl-examples') . '.sock';
        $listener = Unix\listen($sock);

        $first = Async\run($listener->accept(...));

        [$second_connection, $client_one, $client_two] = Async\concurrently([
            $listener->accept(...),
            static fn(): Network\StreamInterface => Unix\connect($sock),
            static fn(): Network\StreamInterface => Unix\connect($sock),
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
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(8)) . '.sock';

        try {
            $listener = Unix\listen($sock);
            $reference = WeakReference::create($listener);
            unset($listener);

            static::assertNull($reference->get());
        } finally {
            @unlink($sock);
        }
    }

    public function testAcceptingListenerIsDestroyedOnceNoLongerReferenced(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(8)) . '.sock';

        try {
            $callbacks = self::getReferencedCallbacks();

            $listener = Unix\listen($sock);
            $client = Unix\connect($sock);
            $listener->accept()->close();
            $client->close();

            // accept() enabled the listener's watcher. If the event loop kept the listener alive, the listening
            // socket would never be closed, and the watcher would keep the event loop running forever.
            $reference = WeakReference::create($listener);
            unset($listener);

            static::assertNull($reference->get());
            static::assertSame([], array_values(array_diff(self::getReferencedCallbacks(), $callbacks)));
        } finally {
            @unlink($sock);
        }
    }

    public function testWaitingAcceptKeepsListenerAlive(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(8)) . '.sock';

        try {
            $listener = Unix\listen($sock);
            $accept = Async\run($listener->accept(...));
            unset($listener);

            // Once accept() waits, the listener is only referenced by the waiting fiber, which is itself only
            // referenced by the listener. The event loop must keep both alive until a connection comes in.
            Async\later();
            gc_collect_cycles();

            $client = Unix\connect($sock);
            $server = $accept->await();

            $client->write('ping');
            static::assertSame('ping', $server->read(4));

            $client->close();
            $server->close();
        } finally {
            @unlink($sock);
        }
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
