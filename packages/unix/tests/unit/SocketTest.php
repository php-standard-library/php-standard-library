<?php

declare(strict_types=1);

namespace Psl\Unix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\Unix;

use function file_exists;
use function getmypid;
use function sys_get_temp_dir;
use function unlink;

use const PHP_OS_FAMILY;

final class SocketTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            static::markTestSkipped('Unix sockets are not supported on Windows.');
        }
    }

    public function testCreate(): void
    {
        $socket = Unix\Socket::create();

        static::assertInstanceOf(Unix\Socket::class, $socket);
    }

    public function testBindAndGetLocalAddress(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_socket_test_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $socket = Unix\Socket::create();
            $socket->bind($path);

            $address = $socket->getLocalAddress();
            static::assertSame($path, $address->host);
        } finally {
            @unlink($path);
        }
    }

    public function testConnectAndCommunicate(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_connect_test_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $listener = Unix\listen($path);

            Async\concurrently([
                'server' => static function () use ($listener): void {
                    $conn = $listener->accept();
                    $data = $conn->read();
                    static::assertSame('socket-hello', $data);
                    $conn->writeAll('socket-response');
                    $conn->close();
                    $listener->close();
                },
                'client' => static function () use ($path): void {
                    $socket = Unix\Socket::create();
                    $stream = $socket->connect($path);

                    $stream->writeAll('socket-hello');
                    $response = $stream->readAll();
                    static::assertSame('socket-response', $response);
                    $stream->close();
                },
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function testListenAndAccept(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_listen_test_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $socket = Unix\Socket::create();
            $socket->bind($path);
            $listener = $socket->listen();

            Async\concurrently([
                'server' => static function () use ($listener): void {
                    $conn = $listener->accept();
                    $data = $conn->read();
                    static::assertSame('ping', $data);
                    $conn->writeAll('pong');
                    $conn->close();
                    $listener->close();
                },
                'client' => static function () use ($path): void {
                    $client = Unix\connect($path);
                    $client->writeAll('ping');
                    $response = $client->readAll();
                    static::assertSame('pong', $response);
                    $client->close();
                },
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function testConsumedSocketThrows(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_consumed_test_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $socket = Unix\Socket::create();
            $socket->bind($path);
            $listener = $socket->listen();

            $this->expectException(Network\Exception\RuntimeException::class);
            $this->expectExceptionMessage('Socket has already been consumed');

            $socket->bind($path);

            $listener->close();
        } finally {
            @unlink($path);
        }
    }

    public function testConnectWithTimeout(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_timeout_test_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $listener = Unix\listen($path);

            Async\concurrently([
                'server' => static function () use ($listener): void {
                    $conn = $listener->accept();
                    $data = $conn->read();
                    static::assertSame('with-timeout', $data);
                    $conn->writeAll('ok');
                    $conn->close();
                    $listener->close();
                },
                'client' => static function () use ($path): void {
                    $socket = Unix\Socket::create();
                    $stream = $socket->connect($path, new Async\TimeoutCancellationToken(Duration::seconds(5)));

                    $stream->writeAll('with-timeout');
                    $response = $stream->readAll();
                    static::assertSame('ok', $response);
                    $stream->close();
                },
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function testConnectConsumedByConnect(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_consumed_connect_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $listener = Unix\listen($path);

            $socket = Unix\Socket::create();
            $stream = $socket->connect($path);
            $stream->close();
            $listener->close();

            $this->expectException(Network\Exception\RuntimeException::class);
            $this->expectExceptionMessage('Socket has already been consumed');

            $socket->connect($path);
        } finally {
            @unlink($path);
        }
    }

    public function testGetLocalAddressOnConsumedSocketThrows(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_addr_consumed_' . getmypid() . '.sock';
        @unlink($path);

        try {
            $socket = Unix\Socket::create();
            $socket->bind($path);
            $listener = $socket->listen();

            $this->expectException(Network\Exception\RuntimeException::class);
            $this->expectExceptionMessage('Socket has already been consumed');

            $socket->getLocalAddress();

            $listener->close();
        } finally {
            @unlink($path);
        }
    }

    public function testBindRejectsPathContainingNullByte(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_nul_bind_' . getmypid() . '.sock';
        @unlink($path);

        $socket = Unix\Socket::create();

        try {
            $socket->bind($path . "\0suffix");
            // Without the check, the path is cut at the NUL byte and this binds a socket at $path.
            $socket->listen()->close();

            static::fail('Expected an InvalidArgumentException to be thrown.');
        } catch (Network\Exception\InvalidArgumentException $exception) {
            static::assertSame('Unix socket path must not contain NULL bytes.', $exception->getMessage());
        } finally {
            $bound = file_exists($path);
            @unlink($path);
        }

        static::assertFalse($bound);

        // The rejected path does not consume the socket.
        $socket->bind($path);
        static::assertSame($path, $socket->getLocalAddress()->host);
    }

    public function testConnectRejectsPathContainingNullByte(): void
    {
        $path = sys_get_temp_dir() . '/psl_unix_nul_connect_' . getmypid() . '.sock';
        @unlink($path);

        $listener = Unix\listen($path);

        try {
            $this->expectException(Network\Exception\InvalidArgumentException::class);
            $this->expectExceptionMessage('Unix socket path must not contain NULL bytes.');

            // Without the check, the path is cut at the NUL byte and this connects to $path.
            Unix\Socket::create()->connect($path . "\0suffix");
        } finally {
            $listener->close();
            @unlink($path);
        }
    }

    public function testGetLocalAddressWithoutBindThrows(): void
    {
        $socket = Unix\Socket::create();

        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Socket has not been bound');

        $socket->getLocalAddress();
    }

    public function testListenWithoutBindThrows(): void
    {
        $socket = Unix\Socket::create();

        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Cannot listen without binding');

        $socket->listen();
    }
}
