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
}
