<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\Exception\CancelledException;
use Psl\DateTime\Duration;
use Psl\H2;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Client\Internal\H2\H2Stream;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\URL;

use function str_repeat;

final class CancellationTest extends TestCase
{
    #[DataProvider('provideCancellationOrders')]
    public function testDataAndCancellationWakeBodyReaderOnce(bool $cancelFirst): void
    {
        $stream = new H2Stream(1);
        $cancellation = new Async\SignalCancellationToken();
        $reader = Async\run(static fn() => $stream->readBody(cancellation: $cancellation));
        $reader->ignore();
        Async\later();

        if ($cancelFirst) {
            $cancellation->cancel();
        }

        $stream->handleData('data', false);
        $cancellation->cancel();

        if ($cancelFirst) {
            $this->expectException(CancelledException::class);
            $reader->await();
        } else {
            static::assertSame('data', $reader->await());
        }
    }

    /**
     * @return list<array{bool}>
     */
    public static function provideCancellationOrders(): array
    {
        return [[true], [false]];
    }

    public function testCancelledStreamSlotWaitIsRemoved(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $session->updateMaxConcurrentStreams(1);
        $session->acquireStream();
        $cancellation = new Async\SignalCancellationToken();
        $waiter = Async\run(static function () use ($session, $cancellation): bool {
            try {
                $session->acquireStream($cancellation);
                return false;
            } catch (CancelledException) {
                return true;
            }
        });
        $waiter->ignore();

        try {
            Async\later();
            $cancellation->cancel();
            Async\later();
            static::assertTrue($waiter->isComplete());
            static::assertTrue($waiter->await());
            static::assertFalse($session->hasStreamWaiters());
            $session->releaseStream();
            $session->acquireStream(new Async\TimeoutCancellationToken(Duration::milliseconds(20)));
            $session->releaseStream();
        } finally {
            $session->markClosed();
            $client->close();
            $server->close();
        }
    }

    public function testZeroLimitWaiterKeepsReadingSettingsAfterWakeup(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $session->updateMaxConcurrentStreams(0);
        $h2 = new H2\ServerConnection($server, new H2\Configuration(settings: [
            H2\Setting::MaxConcurrentStreams->value => 0,
        ]));
        $h2->initialize();
        $waiter = Async\run(static function () use ($session): void {
            $session->acquireStream(new Async\TimeoutCancellationToken(Duration::milliseconds(50)));
        });
        $waiter->ignore();

        try {
            Async\sleep(Duration::milliseconds(1));
            static::assertFalse($waiter->isComplete());
            $h2->sendSettings([H2\Setting::MaxConcurrentStreams->value => 1]);
            $waiter->await();
            static::assertFalse($session->hasStreamWaiters());
            $session->releaseStream();
        } finally {
            $session->markClosed();
            $client->close();
            $server->close();
        }
    }

    public function testCancelledRequestSendsReset(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $cancellation = new Async\SignalCancellationToken();
        $serverTask = Async\run(static function () use ($server, $cancellation): H2\ErrorCode {
            $h2 = new H2\ServerConnection($server);
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(1))) as $event) {
                    if ($event instanceof H2\Event\HeadersReceived) {
                        $cancellation->cancel();
                    } elseif ($event instanceof H2\Event\StreamReset) {
                        return $event->errorCode;
                    }
                }
            }
        });
        $serverTask->ignore();

        try {
            try {
                $connection->exchange(
                    new Request(method: 'GET', url: URL\parse('http://example.com/')),
                    new ClientConfiguration(),
                    $cancellation,
                );
                static::fail('The request must be cancelled.');
            } catch (CancelledException) {
                static::assertSame(H2\ErrorCode::Cancel, $serverTask->await());
                static::assertFalse($session->isClosed());
            }
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testClosingUnreadBodySendsResetAndReleasesSlot(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $serverTask = Async\run(static function () use ($server): H2\ErrorCode {
            $h2 = new H2\ServerConnection($server, new H2\Configuration(settings: [
                H2\Setting::MaxConcurrentStreams->value => 1,
            ]));
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(1))) as $event) {
                    if ($event instanceof H2\Event\HeadersReceived) {
                        $h2->sendHeadersWithStatus($event->streamId, '200', []);
                    } elseif ($event instanceof H2\Event\StreamReset) {
                        return $event->errorCode;
                    }
                }
            }
        });
        $serverTask->ignore();

        try {
            $transaction = $connection->exchange(
                new Request(method: 'GET', url: URL\parse('http://example.com/')),
                new ClientConfiguration(),
            );
            $body = $transaction->response->body;
            static::assertInstanceOf(IO\CloseHandleInterface::class, $body);
            $body->close();
            static::assertSame(H2\ErrorCode::Cancel, $serverTask->await());
            $session->acquireStream(new Async\TimeoutCancellationToken(Duration::milliseconds(20)));
            $session->releaseStream();
            static::assertSame(0, $session->connection->activeStreamCount());
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * @param 'read'|'eof'|'trailers' $failureAt
     */
    #[DataProvider('provideUploadFailures')]
    public function testFailedUploadResetsStreamBeforeNextRequest(string $failureAt, bool $ioError): void
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
            $reset = false;
            while (true) {
                foreach ($h2->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(1))) as $event) {
                    if ($event instanceof H2\Event\StreamReset) {
                        $reset = true;
                        continue;
                    }

                    if (!$event instanceof H2\Event\HeadersReceived) {
                        continue;
                    }

                    if ($event->streamId === 1) {
                        continue;
                    }

                    static::assertTrue($reset);
                    $h2->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
                    return;
                }
            }
        });
        $serverTask->ignore();
        $error = $ioError
            ? new IO\Exception\RuntimeException('Upload failed.')
            : new ProtocolException('Upload failed.');
        $trailers = null;
        if ($failureAt === 'trailers') {
            $body = new IO\MemoryHandle();
            /** @var Async\Deferred<FieldMap> $deferred */
            $deferred = new Async\Deferred();
            $deferred->error($error);
            $trailers = $deferred->getAwaitable();
        } else {
            $body = $this->createStub(IO\ReadHandleInterface::class);
            if ($failureAt === 'eof') {
                $body->method('reachedEndOfDataSource')->willThrowException($error);
            } else {
                $body->method('reachedEndOfDataSource')->willReturn(false);
                $body->method('read')->willThrowException($error);
            }
        }

        $url = URL\parse('http://example.com/');
        $request = new Request(method: 'POST', url: $url, body: $body, trailers: $trailers);
        $configuration = new ClientConfiguration();

        try {
            try {
                $connection->exchange($request, $configuration);
                static::fail('The upload error must fail the stream.');
            } catch (ProtocolException|IO\Exception\RuntimeException $caught) {
                static::assertSame($error, $caught);
            }

            static::assertFalse($session->isClosed());
            static::assertSame(
                200,
                $connection->exchange(new Request(method: 'GET', url: $url), $configuration)->response->status,
            );
            $serverTask->await();
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * @return list<array{'read'|'eof'|'trailers', bool}>
     */
    public static function provideUploadFailures(): array
    {
        return [['read', true], ['eof', true], ['trailers', true], ['read', false]];
    }

    public function testUploadReadsUseRequestCancellation(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $cancellation = new Async\SignalCancellationToken();
        $body = $this->createMock(IO\ReadHandleInterface::class);
        $body->method('reachedEndOfDataSource')->willReturn(false);
        $body
            ->expects(static::once())
            ->method('read')
            ->with(16_384, $cancellation)
            ->willReturnCallback(static function () use ($cancellation): string {
                $cancellation->cancel();
                $cancellation->throwIfCancelled();
                return '';
            });

        try {
            $this->expectException(CancelledException::class);
            $connection->exchange(
                new Request(method: 'POST', url: URL\parse('http://example.com/'), body: $body),
                new ClientConfiguration(),
                $cancellation,
            );
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testCancelledUploadStopsWaitingForSendWindow(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $cancellation = new Async\SignalCancellationToken();
        $serverTask = Async\run(static function () use ($server, $cancellation): H2\ErrorCode {
            $h2 = new H2\ServerConnection($server, new H2\Configuration(settings: [
                H2\Setting::InitialWindowSize->value => 0,
            ]));
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(1))) as $event) {
                    if ($event instanceof H2\Event\HeadersReceived && $event->streamId === 1) {
                        $h2->sendHeadersWithStatus(1, '200', [], endStream: true);
                    } elseif ($event instanceof H2\Event\HeadersReceived) {
                        $cancellation->cancel();
                    } elseif ($event instanceof H2\Event\StreamReset) {
                        return $event->errorCode;
                    }
                }
            }
        });
        $serverTask->ignore();
        $configuration = new ClientConfiguration();
        $url = URL\parse('http://example.com/');

        try {
            $connection->exchange(new Request(method: 'GET', url: $url), $configuration);
            $upload = Async\run(static fn() => $connection->exchange(
                new Request(method: 'POST', url: $url, body: new IO\MemoryHandle(str_repeat('x', 16_384))),
                $configuration,
                $cancellation,
            ));
            $upload->ignore();
            try {
                $upload->await(new Async\TimeoutCancellationToken(Duration::milliseconds(100)));
                static::fail('The upload must be cancelled.');
            } catch (CancelledException) {
                static::assertTrue($upload->isComplete(), 'Cancellation must interrupt the stalled send window.');
                static::assertSame(H2\ErrorCode::Cancel, $serverTask->await());
            }
        } finally {
            $client->close();
            $server->close();
        }
    }
}
