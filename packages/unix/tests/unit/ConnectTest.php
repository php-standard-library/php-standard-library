<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Filesystem;
use Psl\Network;
use Psl\OS;
use Psl\Str;
use Psl\Unix;

use function bin2hex;
use function fclose;
use function fread;
use function random_bytes;
use function stream_socket_accept;
use function stream_socket_server;
use function unlink;

final class ConnectTest extends TestCase
{
    public function testConnect(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = Filesystem\create_temporary_file(prefix: 'psl-examples') . '.sock';

        Async\concurrently([
            'server' => static function () use ($sock): void {
                $listener = Unix\listen($sock);
                self::assertSame("unix://{$sock}", $listener->getLocalAddress()->toString());
                $connection = $listener->accept();
                $request = $connection->read();
                self::assertSame('Hello, World!', $request);
                $connection->writeAll(Str\reverse($request));
                $connection->close();
                $listener->close();
            },
            'client' => static function () use ($sock): void {
                $client = Unix\connect($sock);
                self::assertSame('unix://' . $sock, $client->getPeerAddress()->toString());
                $client->writeAll('Hello, World!');
                $response = $client->readAll();
                self::assertSame('!dlroW ,olleH', $response);
                $client->close();
            },
        ]);
    }

    public function testConnectRejectsPathContainingNullByte(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $file = Filesystem\create_temporary_file(prefix: 'psl-examples');
        $sock = $file . '.sock';
        $listener = Unix\listen($sock);

        try {
            // Without the check, the path is cut at the NUL byte and this connects to $sock.
            $this->expectException(Network\Exception\InvalidArgumentException::class);
            $this->expectExceptionMessage('Unix socket path must not contain NULL bytes.');

            Unix\connect($sock . "\0suffix");
        } finally {
            $listener->close();
            unlink($sock);
            Filesystem\delete_file($file);
        }
    }

    public function testConnectToAbstractSocket(): void
    {
        if (OS\OperatingSystemFamily::Linux !== OS\family()) {
            static::markTestSkipped('The abstract socket namespace is only supported on Linux.');
        }

        // The whole name is significant, NUL bytes after the leading one included.
        $name = "\0psl-unix-test-\0" . bin2hex(random_bytes(8));
        $server = stream_socket_server('unix://' . $name, $errorCode, $errorMessage);
        static::assertNotFalse($server, (string) $errorMessage);

        try {
            $client = Unix\connect($name);
            $connection = stream_socket_accept($server, 1.0);
            static::assertNotFalse($connection);

            $client->writeAll('ping');
            static::assertSame('ping', fread($connection, 4));

            fclose($connection);
            $client->close();
        } finally {
            fclose($server);
        }
    }
}
