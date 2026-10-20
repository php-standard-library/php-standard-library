<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IP;
use Psl\Network;
use Psl\Str;
use Psl\TCP;

use function error_reporting;
use function fclose;
use function stream_socket_server;

final class ConnectTest extends TestCase
{
    public function testRetryConnectorBracketsIpv6FailureAddress(): void
    {
        $connector = $this->createMock(TCP\ConnectorInterface::class);
        $connector
            ->expects(static::once())
            ->method('connect')
            ->willThrowException(new Network\Exception\RuntimeException('refused'));
        $retry = new TCP\RetryConnector($connector, 1);

        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to tcp://[::1]:1234 after 1 attempts.');
        $retry->connect('::1', 1234);
    }

    public function testClosedPortThrows(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $listener->close();

        $this->expectException(Network\Exception\RuntimeException::class);
        TCP\connect('127.0.0.1', $port)->close();
    }

    public function testRetryConnectorRetriesClosedPort(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $listener->close();

        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessage('after 2 attempts. Attempt 1:');
        new TCP\RetryConnector(new TCP\Connector(), 2, Duration::milliseconds(1))
            ->connect('127.0.0.1', $port)
            ->close();
    }

    public function testConcurrentConnectsPreserveErrorReporting(): void
    {
        $listener = TCP\listen('127.0.0.1');
        $port = $listener->getLocalAddress()->port;
        $level = error_reporting();

        try {
            $connect = static function () use ($port, $level): void {
                TCP\connect('127.0.0.1', $port)->close();
                static::assertSame($level, error_reporting());
            };
            Async\concurrently([$connect, $connect]);

            static::assertSame($level, error_reporting());
        } finally {
            error_reporting($level);
            $listener->close();
        }
    }

    public function testIpv6ConnectAndRestrictedAccept(): void
    {
        $probe = @stream_socket_server('tcp://[::1]:0');
        if ($probe === false) {
            static::markTestSkipped('IPv6 loopback sockets are unavailable.');
        }

        fclose($probe);

        $listener = new TCP\RestrictedListener(TCP\listen('::1'), [IP\Address::parse('::1')]);
        $port = $listener->getLocalAddress()->port;
        static::assertSame('::1', $listener->getLocalAddress()->host);

        try {
            Async\concurrently([
                static function () use ($listener): void {
                    $stream = $listener->accept();
                    static::assertSame('::1', $stream->getPeerAddress()->host);
                    static::assertSame('hello', $stream->readFixedSize(5));
                    $stream->close();
                },
                static function () use ($port): void {
                    $stream = TCP\connect('::1', $port);
                    static::assertSame('::1', $stream->getLocalAddress()->host);
                    static::assertSame('::1', $stream->getPeerAddress()->host);
                    static::assertSame("tcp://[::1]:{$port}", $stream->getPeerAddress()->toString());
                    $stream->writeAll('hello');
                    $stream->close();
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    public function testConnect(): void
    {
        Async\concurrently([
            'server' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 8089);
                self::assertSame('tcp://127.0.0.1:8089', $listener->getLocalAddress()->toString());
                $connection = $listener->accept();
                $request = $connection->read();
                self::assertSame('Hello, World!', $request);
                $connection->writeAll(Str\reverse($request));
                $connection->close();
                $listener->close();
            },
            'client' => static function (): void {
                $client = TCP\connect('127.0.0.1', 8089);

                self::assertSame('tcp://127.0.0.1:8089', $client->getPeerAddress()->toString());
                $client->writeAll('Hello, World!');
                $response = $client->readAll();
                self::assertSame('!dlroW ,olleH', $response);
                $client->close();
            },
        ]);
    }
}
