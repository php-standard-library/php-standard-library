<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Connection;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\H2;
use Psl\HPACK;
use Psl\HTTP\Client\Client;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\Origin;
use Psl\HTTP\Client\Connection\PooledConnector;
use Psl\HTTP\Client\Internal\H1\H1Connection;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\ProxyConfiguration;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Psl\URL;

use function extension_loaded;
use function str_contains;

/**
 * @mago-expect lint:excessive-nesting
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final class PooledConnectorTlsTest extends TestCase
{
    private const CERT_FILE = __DIR__ . '/../../../../tls/fixture/certs/server.crt';
    private const KEY_FILE = __DIR__ . '/../../../../tls/fixture/certs/server.key';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('openssl')) {
            static::markTestSkipped('OpenSSL extension is required for TLS tests.');
        }
    }

    public function testTlsConnectionWithH2Alpn(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['h2', 'http/1.1']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;

        /** @var null|string $clientBody */
        $clientBody = null;
        /** @var null|string $connectionClass */
        $connectionClass = null;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                try {
                    $conn = $listener->accept();
                    $tls = $acceptor->accept($conn);

                    $server = new H2\ServerConnection($tls);
                    $server->readClientPreface();
                    $server->initialize();

                    /** @var array<int, true> $handled */
                    $handled = [];

                    while ($server->isConnected()) {
                        try {
                            $events = $server->readEvent(new TimeoutCancellationToken(Duration::milliseconds(200)));
                        } catch (Async\Exception\CancelledException) {
                            break;
                        }

                        foreach ($events as $event) {
                            if (!($event instanceof H2\Event\HeadersReceived && !isset($handled[$event->streamId]))) {
                                continue;
                            }

                            $handled[$event->streamId] = true;
                            Async\Scheduler::defer(static function () use ($server, $event): void {
                                $server->sendHeadersWithStatus(
                                    $event->streamId,
                                    '200',
                                    [new HPACK\Header('content-type', 'text/plain')],
                                    endStream: false,
                                );
                                $server->sendData($event->streamId, 'tls-h2', endStream: true);
                            });
                        }
                    }
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($port, &$clientBody, &$connectionClass): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V20, ProtocolVersion::V11],
                );

                $url = URL\parse('https://127.0.0.1:' . $port . '/');
                $request = new Request(method: 'GET', url: $url);
                $origin = Origin::fromUrl($request->url);

                $connection = $connector->connect(
                    $origin,
                    $request,
                    $configuration,
                    new TimeoutCancellationToken(Duration::seconds(5)),
                );
                $connectionClass = $connection::class;

                $tx = $connection->exchange($request, $configuration);
                $clientBody = $tx->response->body?->readAll() ?? '';
            },
        ]);

        static::assertSame(H2Connection::class, $connectionClass);
        static::assertSame('tls-h2', $clientBody);
    }

    public function testTlsConnectionWithH1Alpn(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['http/1.1']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;

        /** @var null|string $clientBody */
        $clientBody = null;
        /** @var null|string $connectionClass */
        $connectionClass = null;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                try {
                    $conn = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                    $tls = $acceptor->accept($conn);

                    $buffer = '';
                    while (!str_contains($buffer, "\r\n\r\n")) {
                        $chunk = $tls->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
                        if ($chunk === '') {
                            break;
                        }

                        $buffer .= $chunk;
                    }

                    $tls->writeAll("HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\ntls-h1");
                    $tls->close();
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface|Async\Exception\CancelledException
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($port, &$clientBody, &$connectionClass): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V20, ProtocolVersion::V11],
                );

                $url = URL\parse('https://127.0.0.1:' . $port . '/');
                $request = new Request(method: 'GET', url: $url);
                $origin = Origin::fromUrl($request->url);

                $connection = $connector->connect(
                    $origin,
                    $request,
                    $configuration,
                    new TimeoutCancellationToken(Duration::seconds(5)),
                );
                $connectionClass = $connection::class;

                $tx = $connection->exchange($request, $configuration);
                $clientBody = $tx->response->body?->readAll() ?? '';
            },
        ]);

        static::assertSame(H1Connection::class, $connectionClass);
        static::assertSame('tls-h1', $clientBody);
    }

    public function testTlsH2SessionReused(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['h2']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;

        $acceptCount = 0;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor, &$acceptCount): void {
                try {
                    $conn = $listener->accept();
                    $acceptCount++;
                    $tls = $acceptor->accept($conn);

                    $server = new H2\ServerConnection($tls);
                    $server->readClientPreface();
                    $server->initialize();

                    /** @var array<int, true> $handled */
                    $handled = [];

                    while ($server->isConnected()) {
                        try {
                            $events = $server->readEvent(new TimeoutCancellationToken(Duration::milliseconds(200)));
                        } catch (Async\Exception\CancelledException) {
                            break;
                        }

                        foreach ($events as $event) {
                            if (!($event instanceof H2\Event\HeadersReceived && !isset($handled[$event->streamId]))) {
                                continue;
                            }

                            $handled[$event->streamId] = true;
                            Async\Scheduler::defer(static function () use ($server, $event): void {
                                $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: false);
                                $server->sendData($event->streamId, 'h2-reuse', endStream: true);
                            });
                        }
                    }
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($port, &$acceptCount): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V20],
                );

                $client = new Client(connector: $connector, configuration: $configuration);
                $url = URL\parse('https://127.0.0.1:' . $port . '/');

                $tx1 = $client->send(new Request(method: 'GET', url: $url));
                static::assertSame(200, $tx1->response->status);
                $tx1->response->body?->readAll();

                $tx2 = $client->send(new Request(method: 'GET', url: $url));
                static::assertSame(200, $tx2->response->status);
                $body2 = $tx2->response->body?->readAll() ?? '';
                static::assertSame('h2-reuse', $body2);
            },
        ]);

        static::assertSame(1, $acceptCount);
    }

    public function testTlsConcurrentRequestsCoalesceH2Session(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['h2']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;

        $acceptCount = 0;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor, &$acceptCount): void {
                try {
                    $conn = $listener->accept();
                    $acceptCount++;
                    $tls = $acceptor->accept($conn);

                    $server = new H2\ServerConnection($tls);
                    $server->readClientPreface();
                    $server->initialize();

                    /** @var array<int, true> $handled */
                    $handled = [];

                    while ($server->isConnected()) {
                        try {
                            $events = $server->readEvent(new TimeoutCancellationToken(Duration::milliseconds(500)));
                        } catch (Async\Exception\CancelledException) {
                            break;
                        }

                        foreach ($events as $event) {
                            if (!($event instanceof H2\Event\HeadersReceived && !isset($handled[$event->streamId]))) {
                                continue;
                            }

                            $handled[$event->streamId] = true;
                            Async\Scheduler::defer(static function () use ($server, $event): void {
                                $server->sendHeadersWithStatus($event->streamId, '200', [], endStream: false);
                                $server->sendData($event->streamId, 'coalesced-' . $event->streamId, endStream: true);
                            });
                        }
                    }
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|H2\Exception\ExceptionInterface|HPACK\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($port): void {
                $connector = new PooledConnector();
                $client = new Client(
                    connector: $connector,
                    configuration: new ClientConfiguration(
                        tlsConfiguration: TLS\ClientConfiguration::default()
                            ->withPeerVerification(false)
                            ->withAllowSelfSigned(true),
                        protocolVersions: [ProtocolVersion::V20],
                    ),
                );

                $url = URL\parse('https://127.0.0.1:' . $port . '/');
                $tasks = [];
                for ($i = 0; $i < 3; $i++) {
                    $tasks[] = static function () use ($client, $url): string {
                        $tx = $client->send(new Request(method: 'GET', url: $url));
                        return $tx->response->body?->readAll() ?? '';
                    };
                }

                $results = Async\concurrently($tasks);

                static::assertCount(3, $results);
                foreach ($results as $body) {
                    static::assertStringStartsWith('coalesced-', $body);
                }
            },
        ]);

        static::assertSame(1, $acceptCount);
    }

    public function testTlsH1NegotiationConnectsWaitingFibers(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['http/1.1']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;
        $acceptCount = 0;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor, &$acceptCount): void {
                try {
                    for ($i = 0; $i < 3; $i++) {
                        $conn = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                        $acceptCount++;
                        $tls = $acceptor->accept($conn);
                        $reader = new IO\Reader($tls);
                        $reader->readUntil("\r\n\r\n", new TimeoutCancellationToken(Duration::seconds(5)));
                        $tls->writeAll("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                        $tls->close();
                    }
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($port): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V20, ProtocolVersion::V11],
                );

                $url = URL\parse('https://127.0.0.1:' . $port . '/');
                $request = new Request(method: 'GET', url: $url);
                $origin = Origin::fromUrl($request->url);
                $tasks = [];
                for ($i = 0; $i < 3; $i++) {
                    $tasks[] = static function () use ($connector, $origin, $request, $configuration): string {
                        $connection = $connector->connect(
                            $origin,
                            $request,
                            $configuration,
                            new TimeoutCancellationToken(Duration::seconds(5)),
                        );
                        static::assertInstanceOf(H1Connection::class, $connection);
                        $tx = $connection->exchange($request, $configuration);

                        return $tx->response->body?->readAll() ?? '';
                    };
                }

                static::assertSame(['ok', 'ok', 'ok'], Async\concurrently($tasks));
            },
        ]);

        static::assertSame(3, $acceptCount);
    }

    public function testTlsH1KeepAliveConnectionPooledAndReused(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['http/1.1']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $port */
        $port = $listener->getLocalAddress()->port;

        $acceptCount = 0;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor, &$acceptCount): void {
                $handled = 0;
                try {
                    while ($handled < 2) {
                        $conn = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                        $acceptCount++;
                        $tls = $acceptor->accept($conn);

                        while ($handled < 2) {
                            $buffer = '';
                            while (!str_contains($buffer, "\r\n\r\n")) {
                                $chunk = $tls->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
                                if ($chunk === '') {
                                    break 2;
                                }

                                $buffer .= $chunk;
                            }

                            $tls->writeAll("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");
                            $handled++;
                        }
                    }
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface|Async\Exception\CancelledException
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($port): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V11],
                );
                $client = new Client(connector: $connector, configuration: $configuration);

                $url = URL\parse('https://127.0.0.1:' . $port . '/');

                $tx1 = $client->send(new Request(method: 'GET', url: $url));
                static::assertSame(200, $tx1->response->status);
                $tx1->response->body?->readAll();

                $tx2 = $client->send(new Request(method: 'GET', url: $url));
                static::assertSame(200, $tx2->response->status);
                $body2 = $tx2->response->body?->readAll() ?? '';
                static::assertSame('ok', $body2);
            },
        ]);

        static::assertSame(1, $acceptCount);
    }

    public function testForwardProxyWithTlsProxyUrl(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $proxyPort */
        $proxyPort = $listener->getLocalAddress()->port;

        /** @var null|string $connectionClass */
        $connectionClass = null;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                try {
                    $conn = $listener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                    $tls = $acceptor->accept($conn);

                    $buffer = '';
                    while (!str_contains($buffer, "\r\n\r\n")) {
                        $chunk = $tls->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
                        if ($chunk === '') {
                            break;
                        }

                        $buffer .= $chunk;
                    }

                    $tls->writeAll("HTTP/1.1 200 OK\r\nContent-Length: 11\r\nConnection: close\r\n\r\nproxy-works");
                    $tls->close();
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface|Async\Exception\CancelledException
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $listener->close();
                }
            },
            'client' => static function () use ($proxyPort, &$connectionClass): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V11],
                    proxyConfiguration: new ProxyConfiguration(URL\parse("https://127.0.0.1:{$proxyPort}")),
                );

                $url = URL\parse('http://target.example.com:8080/');
                $request = new Request(method: 'GET', url: $url);
                $origin = Origin::fromUrl($request->url);

                $connection = $connector->connect(
                    $origin,
                    $request,
                    $configuration,
                    new TimeoutCancellationToken(Duration::seconds(5)),
                );
                $connectionClass = $connection::class;

                static::assertInstanceOf(H1Connection::class, $connection);
                static::assertTrue($connection->isForwardProxy);

                $tx = $connection->exchange($request, $configuration);
                $body = $tx->response->body?->readAll() ?? '';
                static::assertSame('proxy-works', $body);
            },
        ]);

        static::assertSame(H1Connection::class, $connectionClass);
    }

    public function testHttpsViaConnectTunnelProxy(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['http/1.1']);
        $acceptor = new TLS\Acceptor($serverConfig);

        $proxyListener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $proxyPort */
        $proxyPort = $proxyListener->getLocalAddress()->port;

        $targetListener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        /** @var int<0, 65535> $targetPort */
        $targetPort = $targetListener->getLocalAddress()->port;

        /** @var null|string $clientBody */
        $clientBody = null;

        Async\concurrently([
            'target' => static function () use ($targetListener, $acceptor): void {
                try {
                    $conn = $targetListener->accept(new TimeoutCancellationToken(Duration::seconds(5)));
                    $tls = $acceptor->accept($conn);

                    $buffer = '';
                    while (!str_contains($buffer, "\r\n\r\n")) {
                        $chunk = $tls->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
                        if ($chunk === '') {
                            break;
                        }

                        $buffer .= $chunk;
                    }

                    $tls->writeAll("HTTP/1.1 200 OK\r\nContent-Length: 13\r\nConnection: close\r\n\r\ntunnel-works!");
                    $tls->close();
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|TLS\Exception\ExceptionInterface|Async\Exception\CancelledException
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $targetListener->close();
                }
            },
            'proxy' => static function () use ($proxyListener, $targetPort): void {
                try {
                    $proxyConn = $proxyListener->accept(new TimeoutCancellationToken(Duration::seconds(5)));

                    $buffer = '';
                    while (!str_contains($buffer, "\r\n\r\n")) {
                        $chunk = $proxyConn->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
                        if ($chunk === '') {
                            break;
                        }

                        $buffer .= $chunk;
                    }

                    static::assertStringContainsString('CONNECT', $buffer);

                    $proxyConn->writeAll("HTTP/1.1 200 Connection Established\r\n\r\n");

                    $target = TCP\connect('127.0.0.1', $targetPort);

                    $clientToTarget = Async\run(static function () use ($proxyConn, $target): void {
                        try {
                            while (true) {
                                $data = $proxyConn->read(cancellation: new TimeoutCancellationToken(Duration::seconds(
                                    2,
                                )));
                                if ($data === '') {
                                    break;
                                }

                                $target->writeAll($data);
                            }
                        } catch (
                            IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|Async\Exception\CancelledException
                        ) {
                            // @mago-expect lint:no-empty-catch-clause
                        }
                    });

                    $targetToClient = Async\run(static function () use ($target, $proxyConn): void {
                        try {
                            while (true) {
                                $data = $target->read(cancellation: new TimeoutCancellationToken(Duration::seconds(2)));
                                if ($data === '') {
                                    break;
                                }

                                $proxyConn->writeAll($data);
                            }
                        } catch (
                            IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|Async\Exception\CancelledException
                        ) {
                            // @mago-expect lint:no-empty-catch-clause
                        }
                    });

                    $clientToTarget->await();
                    $targetToClient->await();
                } catch (
                    IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface|Async\Exception\CancelledException
                ) {
                    // @mago-expect lint:no-empty-catch-clause
                } finally {
                    $proxyListener->close();
                }
            },
            'client' => static function () use ($proxyPort, $targetPort, &$clientBody): void {
                $connector = new PooledConnector();
                $configuration = new ClientConfiguration(
                    tlsConfiguration: TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true),
                    protocolVersions: [ProtocolVersion::V11],
                    proxyConfiguration: new ProxyConfiguration(URL\parse("http://127.0.0.1:{$proxyPort}")),
                );

                $url = URL\parse("https://127.0.0.1:{$targetPort}/");
                $request = new Request(method: 'GET', url: $url);
                $origin = Origin::fromUrl($request->url);

                $connection = $connector->connect(
                    $origin,
                    $request,
                    $configuration,
                    new TimeoutCancellationToken(Duration::seconds(5)),
                );

                static::assertInstanceOf(H1Connection::class, $connection);

                $tx = $connection->exchange($request, $configuration);
                $clientBody = $tx->response->body?->readAll() ?? '';
            },
        ]);

        static::assertSame('tunnel-works!', $clientBody);
    }
}
