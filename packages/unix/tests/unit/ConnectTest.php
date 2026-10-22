<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
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
use function sys_get_temp_dir;
use function unlink;

final class ConnectTest extends TestCase
{
    /**
     * The errno of a connect to a Unix socket whose backlog is full, on Linux (EAGAIN).
     */
    private const int LINUX_BACKLOG_FULL_ERRNO = 11;

    /**
     * The errno of a connect to a Unix socket whose backlog is full, on macOS (ECONNREFUSED).
     */
    private const int DARWIN_BACKLOG_FULL_ERRNO = 61;

    /**
     * How many connects may queue on a listener with a backlog of one before one fails, generously.
     */
    private const int MAXIMUM_QUEUED_CONNECTS = 16;

    /**
     * How long the backlog test may take before it counts a connect as waiting, generously.
     */
    private const int DEADLINE_MILLISECONDS = 5_000;

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

    /**
     * A Unix-domain connect has connected by the time the socket exists, so it returns without waiting: a token that
     * ends any wait it is subscribed to does not end it.
     */
    public function testConnectReturnsWithoutWaiting(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        $sock = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(8)) . '.sock';
        $listener = Unix\listen($sock);

        try {
            $client = Unix\connect($sock, new InlineCancellationToken());
            $server = $listener->accept();
            $client->writeAll('ping');

            static::assertSame('ping', $server->readFixedSize(4));

            $client->close();
            $server->close();
        } finally {
            $listener->close();
            @unlink($sock);
        }
    }

    /**
     * A connect to a listener whose backlog is full fails at once with the errno, instead of waiting.
     */
    public function testConnectFailsAtOnceWhenTheBacklogIsFull(): void
    {
        $errno = match (OS\family()) {
            OS\OperatingSystemFamily::Linux => self::LINUX_BACKLOG_FULL_ERRNO,
            OS\OperatingSystemFamily::Darwin => self::DARWIN_BACKLOG_FULL_ERRNO,
            default => null,
        };

        if (null === $errno) {
            static::markTestSkipped('The errno of a full Unix backlog is only known on Linux and macOS.');
        }

        $sock = sys_get_temp_dir() . '/psl-' . bin2hex(random_bytes(8)) . '.sock';
        // The listener never accepts, so connects queue until the backlog is full.
        $listener = Unix\listen($sock, new Unix\ListenConfiguration(backlog: 1));
        $deadline = new Async\TimeoutCancellationToken(Duration::milliseconds(self::DEADLINE_MILLISECONDS));
        $clients = [];
        $failure = null;

        try {
            for ($i = 0; $i < self::MAXIMUM_QUEUED_CONNECTS && null === $failure; $i++) {
                try {
                    $clients[] = Unix\connect($sock, $deadline);
                } catch (Network\Exception\RuntimeException $e) {
                    $failure = $e;
                }
            }

            static::assertNotNull($failure, 'No connect failed on a full backlog.');
            static::assertSame($errno, $failure->getCode());
        } finally {
            foreach ($clients as $client) {
                $client->close();
            }

            $listener->close();
            @unlink($sock);
        }
    }

    public function testAddressesOfPathContainingColon(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('Unix Server is not supported on Windows platform.');
        }

        // A name containing ":" used to be read as a TCP host and port: "tcp://<dir>/psl-colon:8080".
        $file = Filesystem\create_temporary_file(prefix: 'psl-colon');
        $sock = $file . ':8080.sock';
        $listener = Unix\listen($sock);

        try {
            [$server, $client] = Async\concurrently([
                $listener->accept(...),
                static fn(): Unix\StreamInterface => Unix\connect($sock),
            ]);

            static::assertSame('unix://' . $sock, $listener->getLocalAddress()->toString());
            static::assertSame('unix://' . $sock, $server->getLocalAddress()->toString());
            static::assertSame('unix://' . $sock, $client->getPeerAddress()->toString());
            // The client is unnamed: the path is its peer's.
            static::assertSame('unix://<anonymous>', $client->getLocalAddress()->toString());

            $client->close();
            $server->close();
        } finally {
            $listener->close();
            unlink($sock);
            Filesystem\delete_file($file);
        }
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
