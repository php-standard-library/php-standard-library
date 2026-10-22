<?php

declare(strict_types=1);

namespace Psl\Socks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Socks;
use Psl\TCP;

use function ord;
use function unpack;

final class ConnectorTest extends TestCase
{
    /**
     * How long the proxy waits for the client to close the connection before the test counts it as left open.
     */
    private const int DEADLINE_MILLISECONDS = 2_000;

    public function testConnectNoAuth(): void
    {
        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_100);
                $client = $listener->accept();

                $greeting = $client->readFixedSize(3);
                self::assertSame("\x05\x01\x00", $greeting);

                $client->writeAll("\x05\x00");

                $header = $client->readFixedSize(4);
                self::assertSame("\x05", $header[0]);
                self::assertSame("\x01", $header[1]);
                self::assertSame("\x00", $header[2]);
                self::assertSame("\x03", $header[3]);

                $domainLen = ord($client->readFixedSize(1));
                $domain = $client->readFixedSize($domainLen);
                self::assertSame('target.local', $domain);

                $portBytes = $client->readFixedSize(2);
                $port = unpack('n', $portBytes)[1];
                self::assertSame(8080, $port);

                $client->writeAll("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

                $data = $client->read();
                self::assertSame('hello-proxy', $data);
                $client->writeAll('proxy-reply');
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('127.0.0.1', 18_100));
                $stream = $connector->connect('target.local', 8080);
                $stream->writeAll('hello-proxy');
                $response = $stream->readAll();
                self::assertSame('proxy-reply', $response);
                $stream->close();
            },
        ]);
    }

    public function testConnectWithUsernamePasswordAuth(): void
    {
        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_101);
                $client = $listener->accept();

                $greeting = $client->readFixedSize(4);
                self::assertSame("\x05\x02\x00\x02", $greeting);

                $client->writeAll("\x05\x02");

                $authVersion = $client->readFixedSize(1);
                self::assertSame("\x01", $authVersion);

                $usernameLen = ord($client->readFixedSize(1));
                $username = $client->readFixedSize($usernameLen);
                self::assertSame('user', $username);

                $passwordLen = ord($client->readFixedSize(1));
                $password = $client->readFixedSize($passwordLen);
                self::assertSame('pass', $password);

                $client->writeAll("\x01\x00");

                $header = $client->readFixedSize(4);
                self::assertSame("\x05\x01\x00\x01", $header);

                $client->readFixedSize(4);
                $client->readFixedSize(2);

                $client->writeAll("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

                $data = $client->read();
                self::assertSame('auth-test', $data);
                $client->writeAll('auth-ok');
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new Socks\Connector(
                    new TCP\Connector(),
                    new Socks\Configuration('127.0.0.1', 18_101, 'user', 'pass'),
                );
                $stream = $connector->connect('127.0.0.1', 9999);
                $stream->writeAll('auth-test');
                $response = $stream->readAll();
                self::assertSame('auth-ok', $response);
                $stream->close();
            },
        ]);
    }

    public function testConnectAuthFailure(): void
    {
        $this->expectException(Socks\Exception\AuthenticationException::class);
        $this->expectExceptionMessage('invalid credentials');

        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_102);
                $client = $listener->accept();

                $client->readFixedSize(4);
                $client->writeAll("\x05\x02");

                $client->readFixedSize(1);
                $usernameLen = ord($client->readFixedSize(1));
                $client->readFixedSize($usernameLen);
                $passwordLen = ord($client->readFixedSize(1));
                $client->readFixedSize($passwordLen);

                $client->writeAll("\x01\x01");
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new Socks\Connector(
                    new TCP\Connector(),
                    new Socks\Configuration('127.0.0.1', 18_102, 'bad', 'creds'),
                );
                $connector->connect('example.com', 80);
            },
        ]);
    }

    public function testConnectConnectionRefused(): void
    {
        $this->expectException(Socks\Exception\SocksException::class);
        $this->expectExceptionMessage('connection refused');

        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_103);
                $client = $listener->accept();

                $client->readFixedSize(3);
                $client->writeAll("\x05\x00");

                $header = $client->readFixedSize(4);
                $domainLen = ord($client->readFixedSize(1));
                $client->readFixedSize($domainLen);
                $client->readFixedSize(2);

                $client->writeAll("\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('127.0.0.1', 18_103));
                $connector->connect('unreachable.local', 80);
            },
        ]);
    }

    public function testConnectWithIPv6Target(): void
    {
        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_104);
                $client = $listener->accept();

                $client->readFixedSize(3);
                $client->writeAll("\x05\x00");

                $header = $client->readFixedSize(4);
                self::assertSame("\x04", $header[3]);

                $client->readFixedSize(16);
                $client->readFixedSize(2);

                $client->writeAll("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

                $data = $client->read();
                self::assertSame('ipv6-test', $data);
                $client->writeAll('ipv6-ok');
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('127.0.0.1', 18_104));
                $stream = $connector->connect('::1', 8080);
                $stream->writeAll('ipv6-test');
                $response = $stream->readAll();
                self::assertSame('ipv6-ok', $response);
                $stream->close();
            },
        ]);
    }

    public function testConnectorUsesProvidedConnector(): void
    {
        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_105);
                $client = $listener->accept();

                $client->readFixedSize(3);
                $client->writeAll("\x05\x00");

                $header = $client->readFixedSize(4);
                $domainLen = ord($client->readFixedSize(1));
                $client->readFixedSize($domainLen);
                $client->readFixedSize(2);

                $client->writeAll("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

                $data = $client->read();
                self::assertSame('custom-connector', $data);
                $client->writeAll('ok');
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $innerConnector = new TCP\StaticConnector('127.0.0.1', 18_105);
                $connector = new Socks\Connector($innerConnector, new Socks\Configuration('ignored', 0));
                $stream = $connector->connect('target.local', 80);
                $stream->writeAll('custom-connector');
                $response = $stream->readAll();
                self::assertSame('ok', $response);
                $stream->close();
            },
        ]);
    }

    /**
     * A handshake that fails closes the connection to the proxy, even though the inner connector keeps a reference
     * to it, and the proxy sees the connection end.
     */
    public function testFailedHandshakeClosesTheProxyConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $proxyPort = $listener->getLocalAddress()->port;
        $inner = new RecordingConnector();

        try {
            [$proxySaw, $failure] = Async\concurrently([
                static function () use ($listener): string {
                    $client = $listener->accept();
                    $client->readFixedSize(3);
                    $client->writeAll("\x05\xFF");

                    // Everything the client sends until it closes the connection.
                    $rest = $client->readAll(cancellation: self::deadline());
                    $client->close();

                    return 'end of stream after ' . $rest;
                },
                static function () use ($inner, $proxyPort): Socks\Exception\SocksException {
                    $connector = new Socks\Connector($inner, new Socks\Configuration('127.0.0.1', $proxyPort));
                    try {
                        $connector->connect('example.com', 80);
                    } catch (Socks\Exception\SocksException $e) {
                        return $e;
                    }

                    static::fail('The handshake did not fail.');
                },
            ]);
        } finally {
            $listener->close();
        }

        static::assertStringContainsString('rejected all offered authentication methods', $failure->getMessage());
        static::assertSame('end of stream after ', $proxySaw);
        static::assertCount(1, $inner->streams);
        static::assertTrue($inner->streams[0]->isClosed());
    }

    /**
     * A handshake that is cancelled closes the connection to the proxy.
     */
    public function testCancelledHandshakeClosesTheProxyConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $proxyPort = $listener->getLocalAddress()->port;
        $inner = new RecordingConnector();
        $token = new Async\SignalCancellationToken();

        try {
            Async\concurrently([
                static function () use ($listener, $token): void {
                    $client = $listener->accept();
                    $client->readFixedSize(3);

                    // The proxy never replies; the client gives up.
                    $token->cancel();
                    $client->readAll(cancellation: self::deadline());
                    $client->close();
                },
                static function () use ($inner, $proxyPort, $token): void {
                    $connector = new Socks\Connector($inner, new Socks\Configuration('127.0.0.1', $proxyPort));
                    $cancelled = false;
                    try {
                        $connector->connect('example.com', 80, $token);
                    } catch (Async\Exception\CancelledException) {
                        $cancelled = true;
                    }

                    static::assertTrue($cancelled, 'The handshake was not cancelled.');
                },
            ]);
        } finally {
            $listener->close();
        }

        static::assertCount(1, $inner->streams);
        static::assertTrue($inner->streams[0]->isClosed());
    }

    public function testRejectedAuthMethod(): void
    {
        $this->expectException(Socks\Exception\SocksException::class);
        $this->expectExceptionMessage('rejected all offered authentication methods');

        Async\concurrently([
            'proxy' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 18_106);
                $client = $listener->accept();

                $client->readFixedSize(3);
                $client->writeAll("\x05\xFF");
                $client->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('127.0.0.1', 18_106));
                $connector->connect('example.com', 80);
            },
        ]);
    }

    /**
     * A token that ends a wait for longer than the test allows.
     */
    private static function deadline(): Async\CancellationTokenInterface
    {
        return new Async\TimeoutCancellationToken(Duration::milliseconds(self::DEADLINE_MILLISECONDS));
    }
}
