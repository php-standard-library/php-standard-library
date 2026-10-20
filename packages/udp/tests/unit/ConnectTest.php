<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\UDP;

use function fclose;
use function stream_socket_server;

final class ConnectTest extends TestCase
{
    public function testIpv6ConnectCanSendAndReceive(): void
    {
        $probe = @stream_socket_server('tcp://[::1]:0');
        if ($probe === false) {
            static::markTestSkipped('IPv6 loopback sockets are unavailable.');
        }

        fclose($probe);

        $server = UDP\Socket::bind('::1');
        $address = $server->getLocalAddress();
        static::assertSame('::1', $address->host);
        $client = UDP\connect('::1', $address->port);

        try {
            static::assertSame('::1', $client->getPeerAddress()->host);
            $client->send('hello');
            [$data, $from] = $server->receiveFrom(1024);
            static::assertSame('hello', $data);
            static::assertSame('::1', $from->host);
            $server->sendTo('world', $from);
            static::assertSame('world', $client->receive(1024));
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testIpv6BoundSocketCanConnect(): void
    {
        $probe = @stream_socket_server('tcp://[::1]:0');
        if ($probe === false) {
            static::markTestSkipped('IPv6 loopback sockets are unavailable.');
        }

        fclose($probe);

        $server = UDP\Socket::bind('::1');
        $client = UDP\Socket::bind('::1')->connect('::1', $server->getLocalAddress()->port);

        try {
            $client->send('hello');
            [$data, $from] = $server->receiveFrom(1024);
            static::assertSame('hello', $data);
            static::assertSame('::1', $from->host);
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testConnectReturnsConnectedSocket(): void
    {
        Async\run(static function (): void {
            $server = UDP\Socket::bind('127.0.0.1', 0);
            $serverAddr = $server->getLocalAddress();

            $connected = UDP\connect($serverAddr->host, $serverAddr->port);

            static::assertInstanceOf(UDP\ConnectedSocket::class, $connected);
            static::assertSame($serverAddr->host, $connected->getPeerAddress()->host);
            static::assertSame($serverAddr->port, $connected->getPeerAddress()->port);

            $connected->close();
            $server->close();
        })->await();
    }

    public function testConnectCanSendAndReceive(): void
    {
        Async\run(static function (): void {
            $server = UDP\Socket::bind('127.0.0.1', 0);
            $serverAddr = $server->getLocalAddress();

            $connected = UDP\connect($serverAddr->host, $serverAddr->port);

            $connected->send('hello');

            [$data, $from] = $server->receiveFrom(1024);
            static::assertSame('hello', $data);

            $server->sendTo('world', $from);

            $response = $connected->receive(1024);
            static::assertSame('world', $response);

            $connected->close();
            $server->close();
        })->await();
    }
}
