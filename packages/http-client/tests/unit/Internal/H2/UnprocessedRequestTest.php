<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
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

use function str_repeat;

final class UnprocessedRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{H2\ErrorCode, null|int, bool}>
     */
    public static function rejections(): iterable
    {
        yield 'refused stream' => [H2\ErrorCode::RefusedStream, null, true];
        yield 'graceful unprocessed GOAWAY' => [H2\ErrorCode::NoError, 0, true];
        yield 'error unprocessed GOAWAY' => [H2\ErrorCode::InternalError, 0, true];
        yield 'GOAWAY includes stream' => [H2\ErrorCode::InternalError, 1, false];
        yield 'GOAWAY includes higher stream' => [H2\ErrorCode::InternalError, 3, false];
        yield 'graceful GOAWAY includes stream' => [H2\ErrorCode::NoError, 1, false];
        foreach (H2\ErrorCode::cases() as $code) {
            if ($code === H2\ErrorCode::RefusedStream) {
                continue;
            }

            yield 'reset ' . $code->name => [$code, null, false];
        }
    }

    #[DataProvider('rejections')]
    public function testPostWithoutBodyRetriesOnlyWhenUnprocessed(
        H2\ErrorCode $code,
        null|int $lastStreamId,
        bool $retry,
    ): void {
        $this->checkRetry($code, $lastStreamId, $retry);
    }

    public function testSeekableBodyReturnsToOriginalPosition(): void
    {
        $body = new IO\MemoryHandle('skip-payment');
        $body->seek(5);
        $this->checkRetry(H2\ErrorCode::RefusedStream, null, true, $body);
        static::assertSame(5, $body->tell());
        static::assertSame('payment', $body->readAll());
    }

    public function testGoAwayReconnectsAndResendsBody(): void
    {
        self::withRejection(H2\ErrorCode::NoError, 0, static function (
            H2Session $session,
            ConnectionMetadata $metadata,
        ): void {
            [$client, $server] = Network\socket_pair();
            $second = new H2Connection(new H2Session($client, new H2ClientConfiguration()), $metadata);
            $serverTask = Async\run(static fn(): string => self::receiveBody($server));
            $connection = new H2Connection($session, $metadata, static fn(): H2Connection => $second);
            $body = new IO\MemoryHandle('skip-payment');
            $body->seek(5);
            try {
                $transaction = $connection->exchange(
                    new Request(method: 'POST', url: URL\parse('http://example.com/'), body: $body),
                    new ClientConfiguration(),
                );
                static::assertSame(200, $transaction->response->status);
                static::assertSame('payment', $serverTask->await());
            } finally {
                $serverTask->ignore();
                $client->close();
                $server->close();
            }
        });
    }

    public function testRefusedStreamDuringUploadRewindsBody(): void
    {
        $body = new IO\MemoryHandle(str_repeat('x', 131_072));
        $this->checkRetry(H2\ErrorCode::RefusedStream, null, true, $body, true);
        static::assertSame(0, $body->tell());
    }

    public function testGoAwayDuringUploadRewindsBody(): void
    {
        $body = new IO\MemoryHandle(str_repeat('x', 131_072));
        $this->checkRetry(H2\ErrorCode::NoError, 0, true, $body, true);
        static::assertSame(0, $body->tell());
    }

    public function testNonSeekableBodyIsNotRetried(): void
    {
        $body = $this->createStub(IO\ReadHandleInterface::class);
        $body->method('reachedEndOfDataSource')->willReturn(false, true, true);
        $body->method('read')->willReturn('payment');
        $this->checkRetry(H2\ErrorCode::RefusedStream, null, false, $body);
    }

    public function testFailedRewindDoesNotRetry(): void
    {
        $body = $this->createMockForIntersectionOfInterfaces([
            IO\ReadHandleInterface::class,
            IO\SeekHandleInterface::class,
        ]);
        $body->method('reachedEndOfDataSource')->willReturn(false, true, true);
        $body->method('read')->willReturn('payment');
        $body->method('tell')->willReturn(5);
        $body
            ->expects(static::once())
            ->method('seek')
            ->with(5)
            ->willThrowException(new IO\Exception\RuntimeException('Cannot seek.'));
        $this->checkRetry(H2\ErrorCode::RefusedStream, null, false, $body);
    }

    public function testFailedTellDoesNotRetry(): void
    {
        $body = $this->createMockForIntersectionOfInterfaces([
            IO\ReadHandleInterface::class,
            IO\SeekHandleInterface::class,
        ]);
        $body->method('reachedEndOfDataSource')->willReturn(false, true, true);
        $body->method('read')->willReturn('payment');
        $body->method('tell')->willThrowException(new IO\Exception\RuntimeException('Cannot tell.'));
        $body->expects(static::never())->method('seek');
        $this->checkRetry(H2\ErrorCode::RefusedStream, null, false, $body);
    }

    public function testReplacementConnectionDoesNotRetryAgain(): void
    {
        self::withRejection(H2\ErrorCode::RefusedStream, null, static function (
            H2Session $first,
            ConnectionMetadata $firstMetadata,
        ): void {
            self::withRejection(H2\ErrorCode::RefusedStream, null, static function (
                H2Session $second,
                ConnectionMetadata $secondMetadata,
            ) use ($first, $firstMetadata): void {
                $reconnects = 0;
                $secondConnection = new H2Connection($second, $secondMetadata, static function () use (
                    &$reconnects,
                ): ConnectionInterface {
                    $reconnects++;
                    throw new RuntimeException('Unexpected second retry.');
                });
                $connection = new H2Connection($first, $firstMetadata, static function () use (
                    $secondConnection,
                    &$reconnects,
                ): ConnectionInterface {
                    $reconnects++;
                    return $secondConnection;
                });
                try {
                    $connection->exchange(
                        new Request(method: 'POST', url: URL\parse('http://example.com/')),
                        new ClientConfiguration(),
                    );
                    static::fail('The second refusal must fail.');
                } catch (RuntimeException $error) {
                    static::assertStringContainsString('RefusedStream', $error->getMessage());
                    static::assertSame(1, $reconnects);
                }
            });
        });
    }

    private static function receiveBody(Network\StreamInterface $server): string
    {
        $peer = new H2\ServerConnection($server);
        $peer->readClientPreface();
        $peer->initialize();
        $received = '';
        while (true) {
            foreach ($peer->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(5))) as $event) {
                if (!$event instanceof H2\Event\DataReceived) {
                    continue;
                }

                $received .= $event->data;
                if (!$event->endStream) {
                    continue;
                }

                $peer->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
                return $received;
            }
        }
    }

    private function checkRetry(
        H2\ErrorCode $code,
        null|int $lastStreamId,
        bool $retry,
        null|IO\ReadHandleInterface $body = null,
        bool $blockUpload = false,
    ): void {
        self::withRejection(
            $code,
            $lastStreamId,
            function (H2Session $session, ConnectionMetadata $metadata) use ($retry, $body): void {
                $request = new Request(method: 'POST', url: URL\parse('http://example.com/'), body: $body);
                $configuration = new ClientConfiguration();
                $transaction = new Transaction([], null, new Response(status: 200));
                $fresh = $this->createMock(ConnectionInterface::class);
                $fresh
                    ->expects($retry ? static::once() : static::never())
                    ->method('exchange')
                    ->with($request, $configuration)
                    ->willReturn($transaction);
                $reconnects = 0;
                $connection = new H2Connection($session, $metadata, static function () use (
                    $fresh,
                    &$reconnects,
                ): ConnectionInterface {
                    $reconnects++;
                    return $fresh;
                });

                try {
                    static::assertSame($transaction, $connection->exchange($request, $configuration));
                    static::assertTrue($retry);
                } catch (RuntimeException) {
                    static::assertFalse($retry);
                }

                static::assertSame($retry ? 1 : 0, $reconnects);
            },
            $blockUpload,
        );
    }

    /**
     * @param Closure(H2Session, ConnectionMetadata): void $test
     */
    private static function withRejection(
        H2\ErrorCode $code,
        null|int $lastStreamId,
        Closure $test,
        bool $blockUpload = false,
    ): void {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $serverTask = Async\run(static function () use ($server, $code, $lastStreamId, $blockUpload): void {
            $peer = new H2\ServerConnection(
                $server,
                new H2\Configuration(settings: $blockUpload ? [H2\Setting::InitialWindowSize->value => 0] : []),
            );
            $peer->readClientPreface();
            $peer->initialize();
            while (true) {
                foreach ($peer->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(5))) as $event) {
                    if (!$event instanceof H2\Event\HeadersReceived) {
                        continue;
                    }

                    if ($lastStreamId === null) {
                        $peer->resetStream($event->streamId, $code);
                        return;
                    }

                    $peer->goAway($code, lastStreamId: $lastStreamId);
                    if ($lastStreamId >= $event->streamId) {
                        $server->close();
                    }

                    return;
                }
            }
        });

        try {
            if ($blockUpload) {
                $session->connection->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(5)));
            }

            $test($session, new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()));
            $serverTask->await();
        } finally {
            $serverTask->ignore();
            $client->close();
            $server->close();
        }
    }
}
