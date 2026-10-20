<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\Str;
use Psl\TCP;

final class ConnectTest extends TestCase
{
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
