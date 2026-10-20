<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use Closure;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\Exception\CancelledException;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\H2;
use Psl\HPACK;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Client\Internal\H2\ResponseBodyHandle;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Response;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\Network;
use Psl\TCP;
use Psl\URL;
use Throwable;

use function chr;
use function str_repeat;
use function strlen;
use function substr;

/**
 * @mago-expect lint:excessive-nesting
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class TransportTest extends TestCase
{
    /**
     * @return array{H2Session, Closure(): void}
     */
    private static function createSession(null|H2ClientConfiguration $h2Config = null): array
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                while ($server->isConnected()) {
                    try {
                        $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause — server cleanup.
            }
        });

        $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $address->port;
        $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

        $h2Config ??= new H2ClientConfiguration();
        $session = new H2Session($stream, $h2Config);

        $teardown = static function () use ($listener, $serverFuture): void {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        };

        return [$session, $teardown];
    }

    /**
     * @param Closure(H2\ServerConnectionInterface, H2\Event\HeadersReceived): void $serverHandler
     *
     * @return array{H2Connection, Transaction}
     */
    private static function exchangeWithServer(
        Closure $serverHandler,
        null|Request $request = null,
        null|ClientConfiguration $config = null,
    ): array {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener, $serverHandler): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                /** @var array<int, bool> $handledStreams */
                $handledStreams = [];

                while ($server->isConnected()) {
                    try {
                        $events = $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (
                            !($event instanceof H2\Event\HeadersReceived && !isset($handledStreams[$event->streamId]))
                        ) {
                            continue;
                        }

                        $handledStreams[$event->streamId] = true;
                        Async\Scheduler::defer(static function () use ($server, $event, $serverHandler): void {
                            $serverHandler($server, $event);
                        });
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause — server cleanup.
            }
        });

        $url = URL\parse('http://127.0.0.1/');
        $request ??= new Request(method: 'GET', url: $url, requestTarget: '/');
        $config ??= new ClientConfiguration();

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

            $h2Config = new H2ClientConfiguration();
            $h2Session = new H2Session($stream, $h2Config);
            $h2Connection = new H2Connection(
                $h2Session,
                new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null),
            );

            $tx = $h2Connection->exchange($request, $config);

            return [$h2Connection, $tx];
        } catch (Throwable $e) {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }

            throw $e;
        }
    }

    public function testSimpleGetRequest(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus(
                $event->streamId,
                '200',
                [
                    new HPACK\Header('content-type', 'text/plain'),
                ],
                endStream: true,
            );
        });

        static::assertSame(200, $tx->response->status);
        static::assertSame(ProtocolVersion::V20, $tx->response->protocolVersion);
        static::assertSame('text/plain', $tx->response->headers->get('content-type'));
        static::assertNull($tx->response->body);
    }

    public function testResponseWithBody(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [
                new HPACK\Header('content-type', 'text/plain'),
            ]);
            $server->sendData($event->streamId, 'Hello World', endStream: true);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);
        static::assertSame('Hello World', $body->readAll());
    }

    public function testResponseWithChunkedBody(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, 'chunk1');
            $server->sendData($event->streamId, 'chunk2');
            $server->sendData($event->streamId, 'chunk3', endStream: true);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);
        static::assertSame('chunk1chunk2chunk3', $body->readAll());
    }

    public function testResponseWithLargeBody(): void
    {
        $expected = str_repeat('ABCDEFGHIJ', 5_000);

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use ($expected): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $offset = 0;
            while ($offset < strlen($expected)) {
                $chunk = substr($expected, $offset, 8_192);
                $offset += strlen($chunk);
                $server->sendData($event->streamId, $chunk, endStream: $offset >= strlen($expected));
            }
        });

        $body = $tx->response->body;
        static::assertNotNull($body);
        $data = $body->readAll();
        static::assertSame(strlen($expected), strlen($data));
        static::assertSame($expected, $data);
    }

    public function test404Response(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '404', [], endStream: true);
        });

        static::assertSame(404, $tx->response->status);
    }

    public function testInformationalResponseBeforeFinal(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '100', []);
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        });

        static::assertSame(200, $tx->response->status);
        static::assertCount(1, $tx->informational);
        static::assertSame(100, $tx->informational[0]->status);
    }

    public function testMultipleInformationalResponses(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '100', []);
            $server->sendHeadersWithStatus($event->streamId, '102', []);
            $server->sendHeadersWithStatus(
                $event->streamId,
                '200',
                [
                    new HPACK\Header('x-done', 'yes'),
                ],
                endStream: true,
            );
        });

        static::assertSame(200, $tx->response->status);
        static::assertCount(2, $tx->informational);
        static::assertSame(100, $tx->informational[0]->status);
        static::assertSame(102, $tx->informational[1]->status);
        static::assertSame('yes', $tx->response->headers->get('x-done'));
    }

    public function testInformationalCallbackFiredForSingleResponse(): void
    {
        $received = [];
        [, $tx] = self::exchangeWithServer(
            static function (H2\ServerConnectionInterface $server, H2\Event\HeadersReceived $event): void {
                $server->sendHeadersWithStatus($event->streamId, '103', [
                    new HPACK\Header('link', '</style.css>; rel=preload'),
                ]);
                $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
            },
            config: new ClientConfiguration(onInformationalResponse: static function (Response $r) use (
                &$received,
            ): void {
                $received[] = $r->status;
            }),
        );

        static::assertSame([103], $received);
        static::assertCount(1, $tx->informational);
        static::assertSame(103, $tx->informational[0]->status);
    }

    public function testInformationalCallbackFiredForMultipleResponses(): void
    {
        $received = [];
        [, $tx] = self::exchangeWithServer(
            static function (H2\ServerConnectionInterface $server, H2\Event\HeadersReceived $event): void {
                $server->sendHeadersWithStatus($event->streamId, '100', []);
                $server->sendHeadersWithStatus($event->streamId, '103', [
                    new HPACK\Header('link', '</a>'),
                ]);
                $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
            },
            config: new ClientConfiguration(onInformationalResponse: static function (Response $r) use (
                &$received,
            ): void {
                $received[] = $r->status;
            }),
        );

        static::assertSame([100, 103], $received);
        static::assertCount(2, $tx->informational);
    }

    public function testInformationalCallbackNullDoesNotBreak(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '100', []);
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, config: new ClientConfiguration(onInformationalResponse: null));

        static::assertCount(1, $tx->informational);
        static::assertSame(200, $tx->response->status);
    }

    public function testMultiplexedInformationalCallbacksAreIsolated(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                /** @var array<int, bool> $handledStreams */
                $handledStreams = [];

                while ($server->isConnected()) {
                    try {
                        $events = $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (
                            !($event instanceof H2\Event\HeadersReceived && !isset($handledStreams[$event->streamId]))
                        ) {
                            continue;
                        }

                        $handledStreams[$event->streamId] = true;
                        Async\Scheduler::defer(static function () use ($server, $event): void {
                            $server->sendHeadersWithStatus($event->streamId, '103', [
                                new HPACK\Header('x-stream', (string) $event->streamId),
                            ]);
                            Async\sleep(Duration::milliseconds(50));
                            $server->sendHeadersWithStatus(
                                $event->streamId,
                                '200',
                                [
                                    new HPACK\Header('x-stream', (string) $event->streamId),
                                ],
                                endStream: true,
                            );
                        });
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

            $h2Session = new H2Session($stream, new H2ClientConfiguration());

            $url = URL\parse('http://127.0.0.1/');
            $request1 = new Request(method: 'GET', url: $url, requestTarget: '/a');
            $request2 = new Request(method: 'GET', url: $url, requestTarget: '/b');

            $received1 = [];
            $config1 = new ClientConfiguration(onInformationalResponse: static function (Response $r) use (
                &$received1,
            ): void {
                $received1[] = $r->headers->get('x-stream');
            });

            $received2 = [];
            $config2 = new ClientConfiguration(onInformationalResponse: static function (Response $r) use (
                &$received2,
            ): void {
                $received2[] = $r->headers->get('x-stream');
            });

            $metadata = new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null);

            $conn1 = new H2Connection($h2Session, $metadata);
            $conn2 = new H2Connection($h2Session, $metadata);

            [$tx1, $tx2] = Async\concurrently([
                static fn() => $conn1->exchange($request1, $config1),
                static fn() => $conn2->exchange($request2, $config2),
            ]);

            static::assertCount(1, $received1);
            static::assertCount(1, $received2);
            static::assertNotSame($received1[0], $received2[0]);

            static::assertCount(1, $tx1->informational);
            static::assertCount(1, $tx2->informational);
            static::assertSame(200, $tx1->response->status);
            static::assertSame(200, $tx2->response->status);
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testResponseWithTrailers(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, 'body data');
            $server->sendHeaders(
                $event->streamId,
                [
                    new HPACK\Header('x-checksum', 'abc123'),
                ],
                endStream: true,
            );
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);
        static::assertSame('body data', $body->readAll());

        static::assertInstanceOf(ResponseBodyHandle::class, $body);
        $trailers = $body->getTrailers();
        static::assertNotNull($trailers);
        static::assertSame('abc123', $trailers->get('x-checksum'));
    }

    public function testPostRequestWithBody(): void
    {
        $requestBody = 'Hello from client';
        $url = URL\parse('http://127.0.0.1/submit');
        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/submit',
            headers: FieldMap::from([
                ['content-type', 'text/plain'],
            ]),
            body: new IO\MemoryHandle($requestBody),
        );

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '201', [], endStream: true);
        }, $request);

        static::assertSame(201, $tx->response->status);
    }

    public function testPostRequestWithTrailers(): void
    {
        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            body: new IO\MemoryHandle('data'),
            trailers: Async\Awaitable::complete(FieldMap::from([
                ['x-checksum', 'deadbeef'],
            ])),
        );

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);

        static::assertSame(200, $tx->response->status);
    }

    public function testTrailersWithoutBodyThrowsRequestException(): void
    {
        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(
            method: 'GET',
            url: $url,
            requestTarget: '/',
            trailers: Async\Awaitable::complete(FieldMap::from([
                ['x-checksum', 'deadbeef'],
            ])),
        );

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Trailers cannot be sent without a message body');

        self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);
    }

    public function testMultipleResponseHeaders(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus(
                $event->streamId,
                '200',
                [
                    new HPACK\Header('set-cookie', 'a=1'),
                    new HPACK\Header('set-cookie', 'b=2'),
                    new HPACK\Header('x-custom', 'value'),
                ],
                endStream: true,
            );
        });

        $cookies = $tx->response->headers->getAll('set-cookie');
        static::assertCount(2, $cookies);
        static::assertSame('value', $tx->response->headers->get('x-custom'));
    }

    public function testStreamResetThrowsProtocolException(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Stream reset');

        self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->resetStream($event->streamId, H2\ErrorCode::InternalError);
        });
    }

    public function testGoAwayNoErrorThrowsRuntimeException(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('HTTP/2 connection is closed');

        self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->goAway(H2\ErrorCode::NoError);
        });
    }

    public function testGoAwayWithErrorThrowsRuntimeException(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Connection closed by server');

        self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->goAway(H2\ErrorCode::InternalError);
        });
    }

    public function testMissingStatusPseudoHeaderThrows(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage(':status');

        self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeaders(
                $event->streamId,
                [
                    new HPACK\Header('content-type', 'text/plain'),
                ],
                endStream: true,
            );
        });
    }

    public function testHeadRequestHasNoBody(): void
    {
        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(method: 'HEAD', url: $url, requestTarget: '/');

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus(
                $event->streamId,
                '200',
                [
                    new HPACK\Header('content-length', '1000'),
                ],
                endStream: true,
            );
        }, $request);

        static::assertSame(200, $tx->response->status);
        static::assertNull($tx->response->body);
    }

    public function testEmptyBodyWithEndStreamOnHeaders(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '204', [], endStream: true);
        });

        static::assertSame(204, $tx->response->status);
        static::assertNull($tx->response->body);
        static::assertEmpty($tx->informational);
    }

    public function testPushedExchangesResolveToEmptyByDefault(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        });

        static::assertNull($tx->pushed);
    }

    public function testStreamResetDuringBodyReadThrows(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, 'partial');
            $server->resetStream($event->streamId, H2\ErrorCode::Cancel);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);

        $this->expectException(IO\Exception\RuntimeException::class);
        $body->readAll();
    }

    public function testGoAwayDuringBodyReadThrows(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, 'partial');
            $server->goAway(H2\ErrorCode::NoError);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);

        $this->expectException(IO\Exception\RuntimeException::class);
        $body->readAll();
    }

    public function testResponseBodySizeLimit(): void
    {
        $config = new ClientConfiguration(maxResponseBodySize: 10);

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, str_repeat('X', 100), endStream: true);
        }, config: $config);

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);

        $this->expectException(IO\Exception\RuntimeException::class);
        $body->readAll();
    }

    public function testConnectionMarkedClosedOnServerDisconnect(): void
    {
        [$h2Connection] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        });

        static::assertInstanceOf(H2Connection::class, $h2Connection);
    }

    public function testBinaryResponseBody(): void
    {
        $binary = '';
        for ($i = 0; $i < 256; $i++) {
            $binary .= chr($i);
        }

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use ($binary): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [
                new HPACK\Header('content-type', 'application/octet-stream'),
            ]);
            $server->sendData($event->streamId, $binary, endStream: true);
        });

        $body = $tx->response->body;
        static::assertNotNull($body);
        static::assertSame($binary, $body->readAll());
    }

    public function testEmptyBodyResponseBody(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, '', endStream: true);
        });

        $body = $tx->response->body;
        if ($body !== null) {
            static::assertSame('', $body->readAll());
        }
    }

    public function testPostWithLargeBody(): void
    {
        $requestBody = str_repeat('X', 32_000);
        $url = URL\parse('http://127.0.0.1/upload');
        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/upload',
            body: new IO\MemoryHandle($requestBody),
        );

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);

        static::assertSame(200, $tx->response->status);
    }

    public function testPostWithEmptyBody(): void
    {
        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(method: 'POST', url: $url, requestTarget: '/', body: new IO\MemoryHandle(''));

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);

        static::assertSame(200, $tx->response->status);
    }

    public function testCustomRequestHeaders(): void
    {
        $receivedHeaders = [];

        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/', headers: FieldMap::from([
            ['accept',        'application/json'],
            ['x-custom',      'test-value'],
            ['authorization', 'Bearer token123'],
        ]));

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use (&$receivedHeaders): void {
            foreach ($event->headers as $header) {
                $receivedHeaders[$header->name] = $header->value;
            }

            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);

        static::assertSame(200, $tx->response->status);
        static::assertSame('GET', $receivedHeaders[':method'] ?? null);
        static::assertSame('/', $receivedHeaders[':path'] ?? null);
        static::assertSame('http', $receivedHeaders[':scheme'] ?? null);
        static::assertSame('application/json', $receivedHeaders['accept'] ?? null);
        static::assertSame('test-value', $receivedHeaders['x-custom'] ?? null);
        static::assertSame('Bearer token123', $receivedHeaders['authorization'] ?? null);
    }

    public function testHostHeaderIsNotSentAsH2Header(): void
    {
        $receivedHeaders = [];

        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/', headers: FieldMap::from([
            ['host', 'example.com'],
        ]));

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use (&$receivedHeaders): void {
            foreach ($event->headers as $header) {
                $receivedHeaders[$header->name] = $header->value;
            }

            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);

        static::assertSame(200, $tx->response->status);
        static::assertArrayNotHasKey('host', $receivedHeaders);
        static::assertArrayHasKey(':authority', $receivedHeaders);
    }

    public function testConnectionAndTransferEncodingHeadersFiltered(): void
    {
        $receivedHeaders = [];

        $url = URL\parse('http://127.0.0.1/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/', headers: FieldMap::from([
            ['connection',        'keep-alive'],
            ['transfer-encoding', 'chunked'],
            ['x-safe',            'ok'],
        ]));

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use (&$receivedHeaders): void {
            foreach ($event->headers as $header) {
                $receivedHeaders[$header->name] = $header->value;
            }

            $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: true);
        }, $request);

        static::assertSame(200, $tx->response->status);
        static::assertArrayNotHasKey('connection', $receivedHeaders);
        static::assertArrayNotHasKey('transfer-encoding', $receivedHeaders);
        static::assertSame('ok', $receivedHeaders['x-safe'] ?? null);
    }

    public function testLargeResponseTriggersWindowUpdates(): void
    {
        $expected = str_repeat('W', 200_000);

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use ($expected): void {
            $server->sendHeadersWithStatus($event->streamId, '200', [
                new HPACK\Header('content-type', 'application/octet-stream'),
            ]);
            $server->sendAllData($event->streamId, $expected, endStream: true);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);
        $data = $body->readAll();
        static::assertSame(strlen($expected), strlen($data));
        static::assertSame($expected, $data);
    }

    public function testVeryLargeResponseRequiresMultipleWindowUpdateRounds(): void
    {
        $expected = str_repeat('R', 500_000);

        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ) use ($expected): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendAllData($event->streamId, $expected, endStream: true);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);
        $data = $body->readAll();
        static::assertSame(strlen($expected), strlen($data));
        static::assertSame($expected, $data);
    }

    public function testTryReadOnResponseBody(): void
    {
        [, $tx] = self::exchangeWithServer(static function (
            H2\ServerConnectionInterface $server,
            H2\Event\HeadersReceived $event,
        ): void {
            $server->sendHeadersWithStatus($event->streamId, '200', []);
            $server->sendData($event->streamId, 'hello world', endStream: true);
        });

        static::assertSame(200, $tx->response->status);
        $body = $tx->response->body;
        static::assertNotNull($body);

        $partial = $body->read(5);
        static::assertSame('hello', $partial);

        $rest = $body->tryRead();
        static::assertSame(' world', $rest);
    }

    public function testIsClosedReturnsTrueAfterMarkClosed(): void
    {
        [$session, $teardown] = self::createSession();

        try {
            static::assertFalse($session->isClosed());
            $session->markClosed();
            static::assertTrue($session->isClosed());
        } finally {
            $teardown();
        }
    }

    public function testAcquireStreamThrowsWhenSessionClosed(): void
    {
        [$session, $teardown] = self::createSession();

        try {
            $session->markClosed();

            $this->expectException(Exception\RuntimeException::class);
            $this->expectExceptionMessage('HTTP/2 connection is closed.');

            $session->acquireStream();
        } finally {
            $teardown();
        }
    }

    public function testAcquireStreamWaitsAndFailsOnClose(): void
    {
        [$session, $teardown] = self::createSession(new H2ClientConfiguration(maxConcurrentStreams: 1));

        try {
            $session->acquireStream();

            $caught = null;
            $waiterFuture = Async\run(static function () use ($session, &$caught): void {
                try {
                    $session->acquireStream();
                } catch (Exception\RuntimeException $e) {
                    $caught = $e;
                }
            });

            Async\later();

            $session->markClosed();

            $waiterFuture->await();

            static::assertNotNull($caught);
            static::assertSame('HTTP/2 connection is closed.', $caught->getMessage());
        } finally {
            $teardown();
        }
    }

    public function testReconnectOnClosedSession(): void
    {
        $listenerA = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $addressA = $listenerA->getLocalAddress();

        $serverFutureA = Async\run(static function () use ($listenerA): void {
            try {
                $conn = $listenerA->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                while ($server->isConnected()) {
                    try {
                        $events = $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (!$event instanceof H2\Event\HeadersReceived) {
                            continue;
                        }

                        $server->goAway(H2\ErrorCode::NoError);
                        return;
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause — server cleanup.
            }
        });

        $listenerB = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $addressB = $listenerB->getLocalAddress();

        $serverFutureB = Async\run(static function () use ($listenerB): void {
            try {
                $conn = $listenerB->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                while ($server->isConnected()) {
                    try {
                        $events = $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (!$event instanceof H2\Event\HeadersReceived) {
                            continue;
                        }

                        $server->sendHeadersWithStatus(
                            $event->streamId,
                            '200',
                            [
                                new HPACK\Header('x-source', 'server-b'),
                            ],
                            endStream: true,
                        );
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause — server cleanup.
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));

            /** @var int<0, 65535> $portA */
            $portA = $addressA->port;
            $streamA = $connector->connect('127.0.0.1', $portA, new TimeoutCancellationToken(Duration::seconds(5)));

            $h2Config = new H2ClientConfiguration();
            $h2Session = new H2Session($streamA, $h2Config);

            /** @var int<0, 65535> $portB */
            $portB = $addressB->port;

            $reconnected = false;
            $reconnect = static function (
                Request $request,
                ClientConfiguration $configuration,
                Async\CancellationTokenInterface $cancellation,
            ) use ($connector, $portB, $h2Config, &$reconnected): H2Connection {
                $reconnected = true;
                $streamB = $connector->connect('127.0.0.1', $portB, new TimeoutCancellationToken(Duration::seconds(5)));
                $sessionB = new H2Session($streamB, $h2Config);
                return new H2Connection(
                    $sessionB,
                    new ConnectionMetadata($streamB->getLocalAddress(), $streamB->getPeerAddress(), null),
                );
            };

            $h2Connection = new H2Connection(
                $h2Session,
                new ConnectionMetadata($streamA->getLocalAddress(), $streamA->getPeerAddress(), null),
                $reconnect,
            );

            $url = URL\parse('http://127.0.0.1/');
            $request = new Request(method: 'GET', url: $url, requestTarget: '/');
            $config = new ClientConfiguration();

            try {
                $h2Connection->exchange($request, $config);
                static::fail('A GOAWAY covering the stream must fail the exchange without a replay.');
            } catch (Exception\RuntimeException $error) {
                static::assertStringContainsString('HTTP/2 connection is closed', $error->getMessage());
                static::assertFalse($reconnected);
            }
        } finally {
            $listenerA->close();
            $listenerB->close();
            try {
                $serverFutureA->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }

            try {
                $serverFutureB->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testGoAwayWithErrorDoesNotReplayExchange(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $reconnected = false;
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
            static function () use (&$reconnected): H2Connection {
                $reconnected = true;
                throw new Exception\RuntimeException('Unexpected replay.');
            },
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

                    $h2->goAway(H2\ErrorCode::InternalError);
                    return;
                }
            }
        });

        try {
            try {
                $connection->exchange(
                    new Request(method: 'GET', url: URL\parse('http://example.com/')),
                    new ClientConfiguration(),
                );
                static::fail('GOAWAY must fail the exchange.');
            } catch (ProtocolException $error) {
                static::assertStringContainsString('Connection closed by server', $error->getMessage());
                static::assertFalse($reconnected);
            }

            $serverTask->await();
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testReconnectAndExchangeThrowsWhenNoReconnectClosure(): void
    {
        [$session, $teardown] = self::createSession();

        try {
            $h2Connection = new H2Connection(
                $session,
                new ConnectionMetadata(
                    Network\Address::tcp('127.0.0.1', 0),
                    Network\Address::tcp('127.0.0.1', 0),
                    null,
                ),
                null,
            );

            $session->markClosed();

            $url = URL\parse('http://127.0.0.1/');
            $request = new Request(method: 'GET', url: $url, requestTarget: '/');
            $config = new ClientConfiguration();

            $this->expectException(Exception\RuntimeException::class);
            $this->expectExceptionMessage('HTTP/2 connection is closed.');

            $h2Connection->exchange($request, $config);
        } finally {
            $teardown();
        }
    }

    public function testConsecutiveExchangesOnSameConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                $count = 0;
                while ($server->isConnected()) {
                    try {
                        $events = $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (!$event instanceof H2\Event\HeadersReceived) {
                            continue;
                        }

                        $count++;
                        $server->sendHeadersWithStatus($event->streamId, '200', [
                            new HPACK\Header('x-count', (string) $count),
                        ]);
                        $server->sendData($event->streamId, "response-{$count}", endStream: true);
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

            $h2Config = new H2ClientConfiguration();
            $h2Session = new H2Session($stream, $h2Config);
            $h2Connection = new H2Connection(
                $h2Session,
                new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null),
            );

            $url = URL\parse('http://127.0.0.1/');
            $request = new Request(method: 'GET', url: $url, requestTarget: '/');
            $config = new ClientConfiguration();

            for ($i = 1; $i <= 3; $i++) {
                $tx = $h2Connection->exchange($request, $config);
                static::assertSame(200, $tx->response->status);
                static::assertSame((string) $i, $tx->response->headers->get('x-count'));
                $body = $tx->response->body;
                static::assertNotNull($body);
                static::assertSame("response-{$i}", $body->readAll());
            }
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testTimeoutOnHungServerDoesNotDeadlock(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                while ($server->isConnected()) {
                    try {
                        $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

            $session = new H2Session($stream, new H2ClientConfiguration());
            $connection = new H2Connection(
                $session,
                new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null),
            );

            $url = URL\parse('http://127.0.0.1/');
            $request = new Request(method: 'GET', url: $url, requestTarget: '/');
            $config = new ClientConfiguration();

            try {
                $connection->exchange($request, $config, new TimeoutCancellationToken(Duration::milliseconds(300)));
                static::fail('Expected CancelledException on timeout');
            } catch (CancelledException) {
                static::addToAssertionCount(1);
            }
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testConcurrentTimeoutsOnHungServerDoNotDeadlock(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                while ($server->isConnected()) {
                    try {
                        $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

            $session = new H2Session($stream, new H2ClientConfiguration());
            $connection = new H2Connection(
                $session,
                new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null),
            );

            $url = URL\parse('http://127.0.0.1/');
            $config = new ClientConfiguration();

            $tasks = [];
            for ($i = 0; $i < 5; $i++) {
                $delay = 100 + ($i * 50);
                $tasks[] = static function () use ($connection, $url, $config, $delay): bool {
                    try {
                        $connection->exchange(
                            new Request(method: 'GET', url: $url, requestTarget: '/'),
                            $config,
                            new TimeoutCancellationToken(Duration::milliseconds($delay)),
                        );
                        return false;
                    } catch (CancelledException) {
                        return true;
                    }
                };
            }

            $results = Async\concurrently($tasks);

            foreach ($results as $i => $cancelled) {
                static::assertTrue($cancelled, "Request {$i} should have been cancelled");
            }
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testConnectionUsableAfterTimeout(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $server = new H2\ServerConnection($conn);
                $server->readClientPreface();
                $server->initialize();

                $requestCount = 0;
                while ($server->isConnected()) {
                    try {
                        $events = $server->readEvent(new TimeoutCancellationToken(Duration::seconds(5)));
                    } catch (CancelledException) {
                        break;
                    }

                    foreach ($events as $event) {
                        if (!$event instanceof H2\Event\HeadersReceived) {
                            continue;
                        }

                        $requestCount++;
                        if ($requestCount <= 1) {
                            continue;
                        }

                        $server->sendHeaders($event->streamId, [
                            new HPACK\Header(':status', '200'),
                            new HPACK\Header('content-length', '2'),
                        ]);
                        $server->sendData($event->streamId, 'ok', endStream: true);
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface
            ) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = $connector->connect('127.0.0.1', $port, new TimeoutCancellationToken(Duration::seconds(5)));

            $session = new H2Session($stream, new H2ClientConfiguration());
            $connection = new H2Connection(
                $session,
                new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null),
            );

            $url = URL\parse('http://127.0.0.1/');
            $config = new ClientConfiguration();
            $request = new Request(method: 'GET', url: $url, requestTarget: '/');

            try {
                $connection->exchange($request, $config, new TimeoutCancellationToken(Duration::milliseconds(200)));
                static::fail('First request should have timed out');
            } catch (CancelledException) {
                static::addToAssertionCount(1);
            }

            $tx = $connection->exchange($request, $config, new TimeoutCancellationToken(Duration::seconds(5)));

            static::assertSame(200, $tx->response->status);
            $body = $tx->response->body?->readAll() ?? '';
            static::assertSame('ok', $body);
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }
}
