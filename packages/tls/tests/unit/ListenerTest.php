<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;

final class ListenerTest extends TestCase
{
    public function testGetLocalAddressDelegatesToInnerListener(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);
        $address = $tcpListener->getLocalAddress();

        $listener = new TLS\Listener($tcpListener, $this->createServerConfig());

        static::assertSame($address->host, $listener->getLocalAddress()->host);
        static::assertSame($address->port, $listener->getLocalAddress()->port);

        $listener->close();
    }

    public function testIsClosedDelegatesToInnerListener(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);
        $listener = new TLS\Listener($tcpListener, $this->createServerConfig());

        static::assertFalse($listener->isClosed());

        $listener->close();

        static::assertTrue($listener->isClosed());
    }

    public function testCloseDelegatesToInnerListener(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);
        $listener = new TLS\Listener($tcpListener, $this->createServerConfig());

        $listener->close();

        static::assertTrue($tcpListener->isClosed());
    }

    public function testAcceptWithCancellation(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);
        $listener = new TLS\Listener($tcpListener, $this->createServerConfig());

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(10));

        Async\run(static function () use ($tcpListener): void {
            Async\sleep(Duration::seconds(5));
            $tcpListener->close();
        })->ignore();

        try {
            Async\run(static function () use ($listener, $token): void {
                $listener->accept($token);
            })->await();

            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        } finally {
            $listener->close();
        }
    }

    public function testAcceptOnClosedListenerThrows(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);
        $listener = new TLS\Listener($tcpListener, $this->createServerConfig());

        $listener->close();

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        Async\run(static function () use ($listener): void {
            $listener->accept();
        })->await();
    }

    public function testAcceptCompletesHandshakeAndExchangesData(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);
        $listener = new TLS\Listener($tcpListener, $this->createServerConfig());
        $port = $listener->getLocalAddress()->port;

        Async\concurrently([
            'server' => static function () use ($listener): void {
                $tls = $listener->accept();
                static::assertInstanceOf(TLS\StreamInterface::class, $tls);

                $data = $tls->read();
                static::assertSame('listener-test', $data);
                $tls->writeAll('listener-ok');
                $tls->close();
                $listener->close();
            },
            'client' => static function () use ($port): void {
                $config = TLS\ClientConfiguration::default()->withPeerVerification(false)->withAllowSelfSigned(true);

                $client = TLS\connect('127.0.0.1', $port, $config);

                $client->writeAll('listener-test');
                $response = $client->readAll();
                static::assertSame('listener-ok', $response);
                $client->close();
            },
        ]);
    }

    public function testRejectsAPendingHandshakeLimitBelowOne(): void
    {
        $tcpListener = TCP\listen('127.0.0.1', 0);

        try {
            new TLS\Listener($tcpListener, $this->createServerConfig(), maxPendingHandshakes: 0);
            static::fail('Expected the limit to be rejected.');
        } catch (Network\Exception\InvalidArgumentException $exception) {
            static::assertSame(
                'Expected $maxPendingHandshakes to be a positive integer, 0 given.',
                $exception->getMessage(),
            );
        } finally {
            $tcpListener->close();
        }
    }

    private function createServerConfig(): TLS\ServerConfiguration
    {
        $certFile = __DIR__ . '/../../fixture/certs/server.crt';
        $keyFile = __DIR__ . '/../../fixture/certs/server.key';

        return TLS\ServerConfiguration::create(new TLS\Certificate($certFile, $keyFile));
    }
}
