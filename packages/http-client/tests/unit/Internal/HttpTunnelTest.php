<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\TimeoutCancellationToken;
use Psl\DateTime\Duration;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Internal\HttpTunnel;
use Psl\HTTP\Client\ProxyConfiguration;
use Psl\HTTP\Client\Tests\Fixture\H1\FakeStream;
use Psl\IO;
use Psl\IO\Reader;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Psl\URL;

final class HttpTunnelTest extends TestCase
{
    public function testConnectPreservesBytesAfterHeaders(): void
    {
        $input = new FakeStream("HTTP/1.1 200 Connection Established\r\nx-proxy: test\r\n\r\ntunnel data");
        $stream = static::createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback($input->read(...));
        $stream->method('reachedEndOfDataSource')->willReturnCallback($input->reachedEndOfDataSource(...));
        $connector = static::createStub(TCP\ConnectorInterface::class);
        $connector->method('connect')->willReturn($stream);

        $tunnel = HttpTunnel::connect(
            $connector,
            new ProxyConfiguration(URL\parse('http://proxy.example/')),
            'example.com',
            443,
            new Async\NullCancellationToken(),
            new TLS\ClientConfiguration(),
        );

        static::assertSame($stream, $tunnel);
        static::assertSame('tunnel data', $tunnel->read());
    }

    public function testConnectRejectsStatusWithoutBoundary(): void
    {
        $input = new FakeStream("HTTP/1.1 2000 Invalid\r\n\r\n");
        $stream = static::createStub(TCP\StreamInterface::class);
        $stream->method('read')->willReturnCallback($input->read(...));
        $stream->method('reachedEndOfDataSource')->willReturnCallback($input->reachedEndOfDataSource(...));
        $connector = static::createStub(TCP\ConnectorInterface::class);
        $connector->method('connect')->willReturn($stream);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid CONNECT response');

        HttpTunnel::connect(
            $connector,
            new ProxyConfiguration(URL\parse('http://proxy.example/')),
            'example.com',
            443,
            new Async\NullCancellationToken(),
            new TLS\ClientConfiguration(),
        );
    }

    public function testConnectTunnelSuccess(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $reader = new Reader($conn);

                // Read lines until empty line (end of CONNECT request headers)
                $firstLine = null;
                while (true) {
                    $line = $reader->readLine();
                    if ($line === null || $line === '') {
                        break;
                    }

                    $firstLine ??= $line;
                }

                static::assertSame('CONNECT example.com:443 HTTP/1.1', $firstLine);

                // Send successful response
                $conn->writeAll("HTTP/1.1 200 Connection Established\r\n\r\n");

                // Echo back any data received (proves tunnel works)
                $data = $conn->read(cancellation: new TimeoutCancellationToken(Duration::seconds(5)));
                $conn->writeAll($data);
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            $cancellation = new TimeoutCancellationToken(Duration::seconds(5));
            $tlsConfig = new TLS\ClientConfiguration();

            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = HttpTunnel::connect(
                $connector,
                new ProxyConfiguration(URL\parse("http://127.0.0.1:{$port}")),
                'example.com',
                443,
                $cancellation,
                $tlsConfig,
            );

            static::assertInstanceOf(TCP\StreamInterface::class, $stream);

            // Write through the tunnel and verify echo
            $stream->writeAll('hello');
            $echoed = $stream->read(cancellation: new TimeoutCancellationToken(Duration::seconds(5)));
            static::assertSame('hello', $echoed);
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testConnectTunnelWithProxyAuth(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $foundAuthHeader = false;

        $serverFuture = Async\run(static function () use ($listener, &$foundAuthHeader): void {
            try {
                $conn = $listener->accept();
                $reader = new Reader($conn);

                // Read lines until empty line (end of CONNECT request headers)
                while (true) {
                    $line = $reader->readLine();
                    if ($line === null || $line === '') {
                        break;
                    }

                    if ($line === 'Proxy-Authorization: Basic dXNlcjpwYXNz') {
                        $foundAuthHeader = true;
                    }
                }

                // Send successful response
                $conn->writeAll("HTTP/1.1 200 Connection Established\r\n\r\n");
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            $cancellation = new TimeoutCancellationToken(Duration::seconds(5));
            $tlsConfig = new TLS\ClientConfiguration();

            /** @var int<0, 65535> $port */
            $port = $address->port;
            $stream = HttpTunnel::connect(
                $connector,
                new ProxyConfiguration(URL\parse("http://127.0.0.1:{$port}"), authorization: 'Basic dXNlcjpwYXNz'),
                'example.com',
                443,
                $cancellation,
                $tlsConfig,
            );

            static::assertInstanceOf(TCP\StreamInterface::class, $stream);
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }

        static::assertTrue($foundAuthHeader, 'Expected Proxy-Authorization header with Basic dXNlcjpwYXNz');
    }

    public function testConnectTunnelNon200Throws(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $reader = new Reader($conn);

                // Drain the CONNECT request
                while (true) {
                    $line = $reader->readLine();
                    if ($line === null || $line === '') {
                        break;
                    }
                }

                $conn->writeAll("HTTP/1.1 403 Forbidden\r\n\r\n");
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            $cancellation = new TimeoutCancellationToken(Duration::seconds(5));
            $tlsConfig = new TLS\ClientConfiguration();

            /** @var int<0, 65535> $port */
            $port = $address->port;

            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessageMatches('/403/');

            HttpTunnel::connect(
                $connector,
                new ProxyConfiguration(URL\parse("http://127.0.0.1:{$port}")),
                'example.com',
                443,
                $cancellation,
                $tlsConfig,
            );
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testConnectTunnelMalformedResponseThrows(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $reader = new Reader($conn);

                // Drain the CONNECT request
                while (true) {
                    $line = $reader->readLine();
                    if ($line === null || $line === '') {
                        break;
                    }
                }

                $conn->writeAll("GARBAGE\r\n\r\n");
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            $cancellation = new TimeoutCancellationToken(Duration::seconds(5));
            $tlsConfig = new TLS\ClientConfiguration();

            /** @var int<0, 65535> $port */
            $port = $address->port;

            $this->expectException(ProtocolException::class);

            HttpTunnel::connect(
                $connector,
                new ProxyConfiguration(URL\parse("http://127.0.0.1:{$port}")),
                'example.com',
                443,
                $cancellation,
                $tlsConfig,
            );
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }

    public function testConnectTunnelEofThrows(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $address = $listener->getLocalAddress();

        $serverFuture = Async\run(static function () use ($listener): void {
            try {
                $conn = $listener->accept();
                $reader = new Reader($conn);

                // Drain the CONNECT request
                while (true) {
                    $line = $reader->readLine();
                    if ($line === null || $line === '') {
                        break;
                    }
                }

                // Close immediately without sending any response
                $conn->close();
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        });

        try {
            $connector = new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
            $cancellation = new TimeoutCancellationToken(Duration::seconds(5));
            $tlsConfig = new TLS\ClientConfiguration();

            /** @var int<0, 65535> $port */
            $port = $address->port;

            $this->expectException(ProtocolException::class);

            HttpTunnel::connect(
                $connector,
                new ProxyConfiguration(URL\parse("http://127.0.0.1:{$port}")),
                'example.com',
                443,
                $cancellation,
                $tlsConfig,
            );
        } finally {
            $listener->close();
            try {
                $serverFuture->await();
            } catch (IO\Exception\ExceptionInterface|Network\Exception\ExceptionInterface) {
                // @mago-expect lint:no-empty-catch-clause
            }
        }
    }
}
