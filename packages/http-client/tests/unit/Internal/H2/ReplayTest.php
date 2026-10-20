<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\H2;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionInterface;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception\RuntimeException;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Response;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\Network;
use Psl\URL;

final class ReplayTest extends TestCase
{
    public function testPostIsNotReplayedAfterConnectionLoss(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $reconnected = false;
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
            static function () use (&$reconnected): ConnectionInterface {
                $reconnected = true;
                throw new RuntimeException('Unexpected replay.');
            },
        );
        $body = new IO\MemoryHandle('payment');
        $serverTask = Async\run(static function () use ($server): string {
            $h2 = new H2\ServerConnection($server);
            $h2->readClientPreface();
            $h2->initialize();
            $received = '';
            while (true) {
                foreach ($h2->readEvent() as $event) {
                    if (!$event instanceof H2\Event\DataReceived) {
                        continue;
                    }

                    $received .= $event->data;
                    if ($event->endStream) {
                        $server->close();
                        return $received;
                    }
                }
            }
        });

        try {
            $connection->exchange(
                new Request(method: 'POST', url: URL\parse('http://example.com/'), body: $body),
                new ClientConfiguration(),
            );
            static::fail('A closed connection must fail the exchange.');
        } catch (RuntimeException $error) {
            static::assertFalse($reconnected, $error->getMessage());
            static::assertTrue($body->reachedEndOfDataSource());
        } finally {
            $client->close();
            $server->close();
        }

        static::assertSame('payment', $serverTask->await());
    }

    public function testStreamResetLeavesSessionOpen(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $serverTask = Async\run(static function () use ($server): void {
            $h2 = new H2\ServerConnection($server);
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent() as $event) {
                    if (!$event instanceof H2\Event\HeadersReceived) {
                        continue;
                    }

                    $h2->resetStream($event->streamId, H2\ErrorCode::Cancel);
                    return;
                }
            }
        });

        try {
            $connection->exchange(
                new Request(method: 'POST', url: URL\parse('http://example.com/')),
                new ClientConfiguration(),
            );
            static::fail('A reset stream must fail the exchange.');
        } catch (RuntimeException) {
            static::assertFalse($session->isClosed());
        } finally {
            $client->close();
            $server->close();
        }

        $serverTask->await();
    }

    public function testClosedSessionReconnectsBeforeSending(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $session->markClosed();
        $body = new IO\MemoryHandle('payment');
        $request = new Request(method: 'POST', url: URL\parse('http://example.com/'), body: $body);
        $configuration = new ClientConfiguration();
        $transaction = new Transaction([], null, new Response(status: 200));
        $fresh = $this->createMock(ConnectionInterface::class);
        $fresh->expects(static::once())->method('exchange')->with($request, $configuration)->willReturn($transaction);
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
            static fn(): ConnectionInterface => $fresh,
        );

        try {
            static::assertSame($transaction, $connection->exchange($request, $configuration));
            static::assertSame(0, $body->tell());
        } finally {
            $client->close();
            $server->close();
        }
    }
}
