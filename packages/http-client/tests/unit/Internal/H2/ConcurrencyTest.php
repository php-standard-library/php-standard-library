<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\H2;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\URL;

final class ConcurrencyTest extends TestCase
{
    public function testPeerLimitHoldsSlotUntilStreamCloses(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration(maxConcurrentStreams: 10));
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $h2 = new H2\ServerConnection($server, new H2\Configuration(settings: [
            H2\Setting::MaxConcurrentStreams->value => 1,
        ]));
        $serverTask = Async\run(static function () use ($h2): void {
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent() as $event) {
                    if (!$event instanceof H2\Event\HeadersReceived) {
                        continue;
                    }

                    $h2->sendHeadersWithStatus($event->streamId, '200', [], endStream: $event->streamId !== 1);
                    if ($event->streamId !== 1) {
                        return;
                    }
                }
            }
        });
        $serverTask->ignore();
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $configuration = new ClientConfiguration();
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(1));

        $firstBody = null;
        try {
            $first = $connection->exchange($request, $configuration, $cancellation);
            $firstBody = $first->response->body;
            $second = Async\run(static fn() => $connection->exchange($request, $configuration, $cancellation));
            $second->ignore();
            Async\sleep(Duration::milliseconds(1));
            static::assertFalse($second->isComplete(), 'Response headers must not release the stream slot.');
            $h2->sendData(1, 'done', endStream: true);
            static::assertSame(200, $second->await()->response->status);
            static::assertSame('done', $first->response->body?->readAll());
            $serverTask->await();
        } finally {
            if ($firstBody instanceof IO\CloseHandleInterface) {
                $firstBody->close();
            }

            $client->close();
            $server->close();
        }
    }

    public function testEarlyResponseReleasesLocallyClosedStreamWithUnreadBody(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $serverTask = Async\run(static function () use ($server): void {
            $h2 = new H2\ServerConnection($server, new H2\Configuration(settings: [
                H2\Setting::MaxConcurrentStreams->value => 1,
            ]));
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent() as $event) {
                    if (!$event instanceof H2\Event\HeadersReceived) {
                        continue;
                    }

                    $h2->sendHeadersWithStatus($event->streamId, '200', [], endStream: $event->streamId !== 1);
                    if ($event->streamId !== 1) {
                        return;
                    }

                    $h2->sendData(1, 'early', endStream: true);
                }
            }
        });
        $serverTask->ignore();
        $sent = false;
        $body = $this->createStub(IO\ReadHandleInterface::class);
        $body->method('reachedEndOfDataSource')->willReturnCallback(static function () use (&$sent): bool {
            return $sent;
        });
        $body->method('read')->willReturnCallback(static function () use (&$sent): string {
            Async\sleep(Duration::milliseconds(5));
            $sent = true;
            return '';
        });
        $url = URL\parse('http://example.com/');
        $configuration = new ClientConfiguration();
        $firstBody = null;

        try {
            $first = $connection->exchange(new Request(method: 'POST', url: $url, body: $body), $configuration);
            $firstBody = $first->response->body;
            $next = $connection->exchange(
                new Request(method: 'GET', url: $url),
                $configuration,
                new Async\TimeoutCancellationToken(Duration::milliseconds(50)),
            );
            static::assertSame(200, $next->response->status);
            static::assertSame('early', $firstBody?->readAll());
            $serverTask->await();
        } finally {
            if ($firstBody instanceof IO\CloseHandleInterface) {
                $firstBody->close();
            }

            $client->close();
            $server->close();
        }
    }

    public function testPeerCanRaiseZeroLimitWhileNoStreamsAreOpen(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $h2 = new H2\ServerConnection($server);
        $serverTask = Async\run(static function () use ($h2): void {
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent() as $event) {
                    if (!$event instanceof H2\Event\HeadersReceived) {
                        continue;
                    }

                    if ($event->streamId === 1) {
                        $h2->sendSettings([H2\Setting::MaxConcurrentStreams->value => 0]);
                    }

                    $h2->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
                    if ($event->streamId !== 1) {
                        return;
                    }
                }
            }
        });
        $serverTask->ignore();
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'));
        $configuration = new ClientConfiguration();
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(1));

        try {
            static::assertSame(200, $connection->exchange($request, $configuration, $cancellation)->response->status);
            $next = Async\run(static fn() => $connection->exchange($request, $configuration, $cancellation));
            $next->ignore();
            Async\sleep(Duration::milliseconds(1));
            static::assertFalse($next->isComplete());
            $h2->sendSettings([H2\Setting::MaxConcurrentStreams->value => 1]);
            static::assertSame(200, $next->await()->response->status);
            $serverTask->await();
        } finally {
            $client->close();
            $server->close();
        }
    }
}
