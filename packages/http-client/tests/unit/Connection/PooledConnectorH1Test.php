<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\HTTP\Client\Client;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\PooledConnector;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\IO\Exception\RuntimeException;
use Psl\Network;
use Psl\TCP;
use Psl\URL;

use function extension_loaded;
use function preg_match;
use function socket_import_stream;
use function socket_set_option;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

use const SO_LINGER;
use const SOL_SOCKET;

/**
 * @mago-expect lint:excessive-nesting
 */
final class PooledConnectorH1Test extends TestCase
{
    /**
     * Read a full HTTP request from a connection (headers + body).
     *
     * @return bool True if a complete request was read, false on connection close/error.
     */
    private static function readRequest(Network\StreamInterface $conn): bool
    {
        $buffer = '';
        while (true) {
            try {
                $chunk = $conn->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|Async\Exception\CancelledException
            ) {
                return false;
            }

            if ($chunk === '') {
                return false;
            }

            $buffer .= $chunk;
            if (str_contains($buffer, "\r\n\r\n")) {
                break;
            }
        }

        // Drain any request body based on Content-Length if present.
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return false;
        }

        $headers = substr($buffer, 0, $headerEnd);
        if (preg_match('/content-length:\s*(\d+)/i', $headers, $m) !== 1) {
            return true;
        }

        $bodyLength = (int) $m[1];
        $bodyReceived = substr($buffer, $headerEnd + 4);
        while (strlen($bodyReceived) < $bodyLength) {
            try {
                $chunk = $conn->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|Async\Exception\CancelledException
            ) {
                return false;
            }

            if ($chunk === '') {
                return false;
            }

            $bodyReceived .= $chunk;
        }

        return true;
    }

    /**
     * Start a simple HTTP/1.1 server that counts accepted connections.
     *
     * The server reads full HTTP requests (waiting for \r\n\r\n) and responds
     * with the given response bytes. It handles up to $maxRequests requests
     * total (across potentially multiple connections).
     *
     * @param int $maxRequests Maximum number of requests to handle before stopping.
     * @param string $response Raw HTTP response bytes to send for each request.
     *
     * @return array{int, Async\Awaitable<int>} Port and a future resolving to the accept count.
     */
    private static function startServer(int $maxRequests, string $response): array
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;

        $future = Async\run(static function () use ($listener, $maxRequests, $response): int {
            $acceptCount = 0;
            $handled = 0;

            try {
                while ($handled < $maxRequests) {
                    $conn = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                    $acceptCount++;

                    // Handle all requests on this connection until the client
                    // disconnects or we've served enough requests.
                    while ($handled < $maxRequests) {
                        if (!self::readRequest($conn)) {
                            break;
                        }

                        try {
                            $conn->writeAll($response);
                        } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                            break;
                        }

                        $handled++;
                    }
                }
            } catch (
                IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|Async\Exception\CancelledException
            ) {
                // @mago-expect lint:no-empty-catch-clause
            } finally {
                $listener->close();
            }

            return $acceptCount;
        });

        return [$port, $future];
    }

    /**
     * After the first request's body is fully consumed, the pooled connection
     * should be returned to the idle pool. The second request to the same
     * origin must reuse it, resulting in only one TCP accept on the server.
     */
    public function testConnectionReusedAfterBodyConsumed(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok";

        [$port, $serverFuture] = self::startServer(2, $response);

        $connector = new PooledConnector();
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V11]);
        $client = new Client(connector: $connector, configuration: $configuration);

        $url = URL\parse('http://127.0.0.1:' . $port . '/first');

        // First request: establish a fresh connection.
        $tx1 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx1->response->status);
        $body1 = $tx1->response->body;
        static::assertNotNull($body1);
        // Fully consume the body so the stream is released back to the pool.
        static::assertSame('ok', $body1->readAll());

        // Second request: should reuse the pooled connection.
        $tx2 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx2->response->status);
        $body2 = $tx2->response->body;
        static::assertNotNull($body2);
        static::assertSame('ok', $body2->readAll());

        $acceptCount = $serverFuture->await();

        // Only one TCP connection should have been accepted: the second
        // request reused the pooled stream.
        static::assertSame(1, $acceptCount);
    }

    /**
     * When the server responds with "Connection: close", the stream must not
     * be returned to the idle pool. A subsequent request must open a new
     * TCP connection, resulting in two accepts.
     */
    public function testConnectionNotReusedWhenNotKeepAlive(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok";

        [$port, $serverFuture] = self::startServer(2, $response);

        $connector = new PooledConnector();
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V11]);
        $client = new Client(connector: $connector, configuration: $configuration);

        $url = URL\parse('http://127.0.0.1:' . $port . '/');

        // First request.
        $tx1 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx1->response->status);
        $body1 = $tx1->response->body;
        static::assertNotNull($body1);
        static::assertSame('ok', $body1->readAll());

        // Second request: must open a new connection because the first
        // was not keep-alive.
        $tx2 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx2->response->status);
        $body2 = $tx2->response->body;
        static::assertNotNull($body2);
        static::assertSame('ok', $body2->readAll());

        $acceptCount = $serverFuture->await();

        // Two TCP connections should have been accepted.
        static::assertSame(2, $acceptCount);
    }

    /**
     * A pooled connection that the server reset while it was idle must be
     * discarded on checkout: the request goes out on a new connection
     * instead of failing with the error of the reset connection.
     */
    public function testResetConnectionDiscardedOnCheckout(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required to reset a connection.');
        }

        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok";
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;
        $pooled = new Async\Deferred();
        $reset = new Async\Deferred();

        $server = Async\run(static function () use ($listener, $response, $pooled, $reset): int {
            try {
                $first = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                static::assertTrue(self::readRequest($first));
                $first->writeAll($response);

                // Once the client has put the connection back in its pool, close it abortively.
                $pooled->getAwaitable()->await(new TimeoutCancellationToken(Duration::seconds(5)));
                $socket = socket_import_stream($first->getStream());
                static::assertNotFalse($socket);
                static::assertTrue(socket_set_option($socket, SOL_SOCKET, SO_LINGER, [
                    'l_onoff' => 1,
                    'l_linger' => 0,
                ]));
                $first->close();
                unset($socket);
                Async\sleep(Duration::milliseconds(50));
                $reset->complete(null);

                $second = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                static::assertTrue(self::readRequest($second));
                $second->writeAll($response);
                $second->close();

                return 2;
            } finally {
                $listener->close();
            }
        });

        $connector = new PooledConnector();
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V11]);
        $client = new Client(connector: $connector, configuration: $configuration);
        $url = URL\parse('http://127.0.0.1:' . $port . '/');

        $tx1 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx1->response->status);
        $body1 = $tx1->response->body;
        static::assertNotNull($body1);
        static::assertSame('ok', $body1->readAll());

        $pooled->complete(null);
        $reset->getAwaitable()->await(new TimeoutCancellationToken(Duration::seconds(5)));

        $tx2 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx2->response->status);
        $body2 = $tx2->response->body;
        static::assertNotNull($body2);
        static::assertSame('ok', $body2->readAll());

        static::assertSame(2, $server->await());
    }

    /**
     * If a pooled connection has been closed (e.g., the server shut down),
     * checkoutH1 should prune it and attempt a new connection. Since the
     * server is down, the new connection attempt will fail.
     */
    public function testClosedConnectionPrunedOnCheckout(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok";

        // Server handles exactly 1 request then stops.
        [$port, $serverFuture] = self::startServer(1, $response);

        $connector = new PooledConnector();
        $configuration = new ClientConfiguration(protocolVersions: [ProtocolVersion::V11]);
        $client = new Client(connector: $connector, configuration: $configuration);

        $url = URL\parse('http://127.0.0.1:' . $port . '/');

        // First request: gets a connection, body consumption returns
        // the stream to the pool.
        $tx1 = $client->send(new Request(method: 'GET', url: $url));
        static::assertSame(200, $tx1->response->status);
        $body1 = $tx1->response->body;
        static::assertNotNull($body1);
        static::assertSame('ok', $body1->readAll());

        // Wait for the server to fully shut down (the future resolves
        // once the listener is closed).
        $acceptCount = $serverFuture->await();
        static::assertSame(1, $acceptCount);

        // The stream is now in the idle pool but the server-side socket
        // is closed. The pool may reuse the dead connection, which will
        // fail when reading the response (ProtocolException), or it may
        // detect the closed stream and try to open a new connection,
        // which fails because the server is no longer listening
        // (Network\RuntimeException). Either outcome is acceptable.
        try {
            $client->send(new Request(method: 'GET', url: $url));
            static::fail('Expected a connection or protocol error');
        } catch (Network\Exception\RuntimeException|ProtocolException|RuntimeException) {
            static::addToAssertionCount(1);
        }
    }
}
