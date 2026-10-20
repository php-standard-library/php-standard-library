<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Str;
use Psl\TCP;
use Psl\TLS;

use function extension_loaded;
use function fclose;
use function stream_socket_server;

final class ConnectTest extends TestCase
{
    private const CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';
    private const KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('openssl')) {
            static::markTestSkipped('OpenSSL extension is required for TLS tests.');
        }
    }

    public function testIpv6ConvenienceConnect(): void
    {
        $probe = @stream_socket_server('tcp://[::1]:0');
        if ($probe === false) {
            static::markTestSkipped('IPv6 loopback sockets are unavailable.');
        }

        fclose($probe);

        $acceptor = new TLS\Acceptor(TLS\ServerConfiguration::create(TLS\Certificate::create(
            self::CERT_FILE,
            self::KEY_FILE,
        )));
        $listener = TCP\listen('::1');
        $port = $listener->getLocalAddress()->port;

        try {
            Async\concurrently([
                static function () use ($listener, $acceptor): void {
                    $stream = $acceptor->accept($listener->accept());
                    static::assertSame('::1', $stream->getPeerAddress()->host);
                    $stream->writeAll('hello');
                    $stream->close();
                },
                static function () use ($port): void {
                    $configuration = TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true);
                    $stream = TLS\connect('::1', $port, $configuration);
                    static::assertSame('::1', $stream->getPeerAddress()->host);
                    static::assertSame('hello', $stream->readFixedSize(5));
                    $stream->close();
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    public function testTlsClientServer(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                $connection = $listener->accept();
                $tls = $acceptor->accept($connection);
                self::assertInstanceOf(TLS\StreamInterface::class, $tls);
                $request = $tls->read();
                self::assertSame('Hello, TLS!', $request);
                $tls->writeAll(Str\reverse($request));
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $stream = TCP\connect('127.0.0.1', $port);
                $connector = new TLS\Connector(
                    TLS\ClientConfiguration::default()->withPeerVerification(false)->withAllowSelfSigned(true),
                );
                $client = $connector->connect($stream, 'localhost');

                self::assertInstanceOf(TLS\StreamInterface::class, $client);

                $state = $client->getState();
                self::assertNotEmpty($state->cipherName);
                self::assertGreaterThan(0, $state->cipherBits);

                $client->writeAll('Hello, TLS!');
                $response = $client->readAll();
                self::assertSame('!SLT ,olleH', $response);
                $client->close();
            },
        ]);
    }

    public function testTlsWithMinimumVersion(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withMinimumVersion(TLS\Version::Tls12);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                $connection = $listener->accept();
                $tls = $acceptor->accept($connection);
                self::assertInstanceOf(TLS\StreamInterface::class, $tls);

                $state = $tls->getState();
                self::assertTrue($state->version === TLS\Version::Tls12 || $state->version === TLS\Version::Tls13);

                $data = $tls->read();
                self::assertSame('ping', $data);
                $tls->writeAll('pong');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $stream = TCP\connect('127.0.0.1', $port);
                $connector = new TLS\Connector(
                    TLS\ClientConfiguration::default()
                        ->withPeerVerification(false)
                        ->withAllowSelfSigned(true)
                        ->withMinimumVersion(TLS\Version::Tls12),
                );
                $client = $connector->connect($stream, 'localhost');

                self::assertInstanceOf(TLS\StreamInterface::class, $client);

                $state = $client->getState();
                self::assertTrue($state->version === TLS\Version::Tls12 || $state->version === TLS\Version::Tls13);
                self::assertNotEmpty($state->cipherName);
                self::assertNull($state->alpnProtocol);

                $client->writeAll('ping');
                $response = $client->readAll();
                self::assertSame('pong', $response);
                $client->close();
            },
        ]);
    }

    public function testConvenienceConnect(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                $connection = $listener->accept();
                $tls = $acceptor->accept($connection);
                $data = $tls->read();
                self::assertSame('convenience-test', $data);
                $tls->writeAll('convenience-ok');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $config = TLS\ClientConfiguration::default()->withPeerVerification(false)->withAllowSelfSigned(true);

                $client = TLS\connect('127.0.0.1', $port, $config);

                self::assertInstanceOf(TLS\StreamInterface::class, $client);
                $client->writeAll('convenience-test');
                $response = $client->readAll();
                self::assertSame('convenience-ok', $response);
                $client->close();
            },
        ]);
    }

    public function testConvenienceConnectWithDefaultConfig(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert)->withMinimumVersion(TLS\Version::Tls12);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                $connection = $listener->accept();
                $tls = $acceptor->accept($connection);
                $data = $tls->read();
                self::assertSame('default-config', $data);
                $tls->writeAll('default-ok');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $config = TLS\ClientConfiguration::default()
                    ->withPeerVerification(false)
                    ->withAllowSelfSigned(true)
                    ->withMinimumVersion(TLS\Version::Tls12);

                $client = TLS\connect('127.0.0.1', $port, $config);

                $state = $client->getState();
                self::assertTrue($state->version === TLS\Version::Tls12 || $state->version === TLS\Version::Tls13);
                self::assertNotEmpty($state->cipherName);

                $client->writeAll('default-config');
                $response = $client->readAll();
                self::assertSame('default-ok', $response);
                $client->close();
            },
        ]);
    }

    public function testConnectPreservesConfigPeerNameWhenServerNameProvided(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $serverConfig): void {
                $connection = $listener->accept();

                $lazy = TLS\LazyAcceptor::default();
                $hello = $lazy->accept($connection);

                static::assertSame('my-custom-peer', $hello->getServerName());

                $tls = $hello->complete($serverConfig);
                $data = $tls->read();
                static::assertSame('peer-name-test', $data);
                $tls->writeAll('ok');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $config = TLS\ClientConfiguration::default()
                    ->withPeerVerification(false)
                    ->withAllowSelfSigned(true)
                    ->withPeerName('my-custom-peer');

                $connector = new TLS\Connector($config);
                $stream = TCP\connect('127.0.0.1', $port);
                $client = $connector->connect($stream, 'different-server-name');

                $client->writeAll('peer-name-test');
                $response = $client->readAll();
                static::assertSame('ok', $response);
                $client->close();
            },
        ]);
    }

    public function testConnectUsesServerNameWhenConfigPeerNameIsNull(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $serverConfig): void {
                $connection = $listener->accept();

                $lazy = TLS\LazyAcceptor::default();
                $hello = $lazy->accept($connection);

                static::assertSame('localhost', $hello->getServerName());

                $tls = $hello->complete($serverConfig);
                $data = $tls->read();
                static::assertSame('server-name-test', $data);
                $tls->writeAll('ok');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $config = TLS\ClientConfiguration::default()->withPeerVerification(false)->withAllowSelfSigned(true);

                static::assertNull($config->peerName);

                $connector = new TLS\Connector($config);
                $stream = TCP\connect('127.0.0.1', $port);
                $client = $connector->connect($stream, 'localhost');

                $client->writeAll('server-name-test');
                $response = $client->readAll();
                static::assertSame('ok', $response);
                $client->close();
            },
        ]);
    }

    public function testConvenienceConnectReturnsTlsStream(): void
    {
        $cert = TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE);
        $serverConfig = TLS\ServerConfiguration::create($cert);
        $acceptor = new TLS\Acceptor($serverConfig);

        $listener = TCP\listen('127.0.0.1', 0);
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener, $acceptor): void {
                $connection = $listener->accept();
                $tls = $acceptor->accept($connection);
                $tls->writeAll('hello');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $config = TLS\ClientConfiguration::default()->withPeerVerification(false)->withAllowSelfSigned(true);

                $client = TLS\connect('127.0.0.1', $port, $config);

                // Verify we get a full TLS stream with state
                self::assertInstanceOf(TLS\StreamInterface::class, $client);
                $state = $client->getState();
                self::assertNotEmpty($state->cipherName);
                self::assertGreaterThan(0, $state->cipherBits);

                $data = $client->readAll();
                self::assertSame('hello', $data);
                $client->close();
            },
        ]);
    }
}
