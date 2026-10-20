<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Binary\Reader;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DNS\Exception\NetworkException;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\ResponseCode;
use Psl\DNS\TCPResolver;
use Psl\Str;
use Psl\TCP;
use ReflectionClass;
use Throwable;

final class TCPResolverTest extends TestCase
{
    public function testPooledQueryReusesConnection(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $serverFuture = Async\run(static function () use ($server): void {
            $client = $server->accept();

            for ($i = 0; $i < 2; $i++) {
                $lengthData = $client->readFixedSize(2);
                /** @var positive-int $length */
                $length = new Reader($lengthData)->u16();
                $query = $client->readFixedSize($length);
                $id = new Reader($query)->u16();

                $answerName = "\x07example\x03com\x00";
                $responsePacket = new Writer()
                    ->u16($id)
                    ->u16(0x8180)
                    ->u16(1)
                    ->u16(1)
                    ->u16(0)
                    ->u16(0)
                    ->bytes($answerName)
                    ->u16(1)
                    ->u16(1)
                    ->bytes($answerName)
                    ->u16(1)
                    ->u16(1)
                    ->u32(300)
                    ->u16(4)
                    ->u8(10)
                    ->u8(0)
                    ->u8(0)
                    ->u8($i + 1)
                    ->toString();

                $tcpResponse = new Writer()
                    ->u16(Str\Byte\length($responsePacket))
                    ->bytes($responsePacket)
                    ->toString();

                $client->writeAll($tcpResponse);
            }

            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $response1 = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response1->code);
        static::assertCount(1, $response1->answers);
        static::assertInstanceOf(ARecord::class, $response1->answers[0]);
        static::assertSame('10.0.0.1', $response1->answers[0]->address->toString());

        $response2 = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response2->code);
        static::assertCount(1, $response2->answers);
        static::assertInstanceOf(ARecord::class, $response2->answers[0]);
        static::assertSame('10.0.0.2', $response2->answers[0]->address->toString());

        $serverFuture->await();
    }

    public function testConnectionErrorDiscardedFromPool(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $serverFuture = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $this->expectException(NetworkException::class);

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $serverFuture->await();
        }
    }

    public function testIdMismatchClearsConnectionFromPool(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $serverFuture = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $lengthData = $client->readFixedSize(2);
            /** @var positive-int $length */
            $length = new Reader($lengthData)->u16();
            $query = $client->readFixedSize($length);
            $id = new Reader($query)->u16();

            $wrongId = ($id + 1) & 0xFFFF;

            $answerName = "\x07example\x03com\x00";
            $responsePacket = new Writer()
                ->u16($wrongId)
                ->u16(0x8180)
                ->u16(1)
                ->u16(1)
                ->u16(0)
                ->u16(0)
                ->bytes($answerName)
                ->u16(1)
                ->u16(1)
                ->bytes($answerName)
                ->u16(1)
                ->u16(1)
                ->u32(300)
                ->u16(4)
                ->u8(10)
                ->u8(0)
                ->u8(0)
                ->u8(1)
                ->toString();

            $tcpResponse = new Writer()
                ->u16(Str\Byte\length($responsePacket))
                ->bytes($responsePacket)
                ->toString();

            $client->writeAll($tcpResponse);

            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/does not match query ID/');

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $serverFuture->await();
        }
    }

    public function testDefaultPortIs53(): void
    {
        $resolver = new TCPResolver(host: '127.0.0.1');

        $reflection = new ReflectionClass($resolver);
        $port = $reflection->getProperty('port')->getValue($resolver);

        static::assertSame(53, $port);
    }

    public function testDefaultDnssecIsFalse(): void
    {
        $resolver = new TCPResolver(host: '127.0.0.1');

        $reflection = new ReflectionClass($resolver);
        $dnssec = $reflection->getProperty('dnssec')->getValue($resolver);

        static::assertFalse($dnssec);
    }

    public function testCustomDnssecIsRespected(): void
    {
        $resolver = new TCPResolver(host: '127.0.0.1', dnssec: true);

        $reflection = new ReflectionClass($resolver);
        $dnssec = $reflection->getProperty('dnssec')->getValue($resolver);

        static::assertTrue($dnssec);
    }

    public function testDestructorClosesPooledConnections(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $connectionClosed = false;

        $serverFuture = Async\run(static function () use ($server, &$connectionClosed): void {
            $client = $server->accept();

            $lengthData = $client->readFixedSize(2);
            /** @var positive-int $length */
            $length = new Reader($lengthData)->u16();
            $query = $client->readFixedSize($length);
            $id = new Reader($query)->u16();

            $answerName = "\x07example\x03com\x00";
            $responsePacket = new Writer()
                ->u16($id)
                ->u16(0x8180)
                ->u16(1)
                ->u16(1)
                ->u16(0)
                ->u16(0)
                ->bytes($answerName)
                ->u16(1)
                ->u16(1)
                ->bytes($answerName)
                ->u16(1)
                ->u16(1)
                ->u32(300)
                ->u16(4)
                ->u8(10)
                ->u8(0)
                ->u8(0)
                ->u8(1)
                ->toString();

            $tcpResponse = new Writer()
                ->u16(Str\Byte\length($responsePacket))
                ->bytes($responsePacket)
                ->toString();

            $client->writeAll($tcpResponse);

            try {
                $client->readFixedSize(1);
            } catch (Throwable) {
                $connectionClosed = true;
            }

            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);
        $resolver->query('example.com', RecordType::A);
        unset($resolver);

        Async\sleep(Duration::milliseconds(50));

        static::assertTrue($connectionClosed);

        $serverFuture->await();
    }

    public function testPoolReusesConnectionAfterSuccessfulQuery(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();
        $connectionCount = 0;

        $serverFuture = Async\run(static function () use ($server, &$connectionCount): void {
            $client = $server->accept();
            $connectionCount++;

            for ($i = 0; $i < 3; $i++) {
                $lengthData = $client->readFixedSize(2);
                /** @var positive-int $length */
                $length = new Reader($lengthData)->u16();
                $query = $client->readFixedSize($length);
                $id = new Reader($query)->u16();

                $answerName = "\x07example\x03com\x00";
                $responsePacket = new Writer()
                    ->u16($id)
                    ->u16(0x8180)
                    ->u16(1)
                    ->u16(1)
                    ->u16(0)
                    ->u16(0)
                    ->bytes($answerName)
                    ->u16(1)
                    ->u16(1)
                    ->bytes($answerName)
                    ->u16(1)
                    ->u16(1)
                    ->u32(300)
                    ->u16(4)
                    ->u8(10)
                    ->u8(0)
                    ->u8(0)
                    ->u8($i + 1)
                    ->toString();

                $tcpResponse = new Writer()
                    ->u16(Str\Byte\length($responsePacket))
                    ->bytes($responsePacket)
                    ->toString();

                $client->writeAll($tcpResponse);
            }

            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $r1 = $resolver->query('example.com', RecordType::A);
        $r2 = $resolver->query('example.com', RecordType::A);
        $r3 = $resolver->query('example.com', RecordType::A);

        static::assertSame(ResponseCode::NoError, $r1->code);
        static::assertSame(ResponseCode::NoError, $r2->code);
        static::assertSame(ResponseCode::NoError, $r3->code);
        static::assertSame(1, $connectionCount);

        $serverFuture->await();
    }

    public function testConnectionErrorThrowsNetworkException(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $serverFuture = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $this->expectException(NetworkException::class);

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $serverFuture->await();
        }
    }

    public function testNetworkExceptionContainsTCPProtocol(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $serverFuture = Async\run(static function () use ($server): void {
            $client = $server->accept();
            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessageMatches('/TCP/');

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $serverFuture->await();
        }
    }

    public function testIdMismatchThrowsProtocolExceptionWithDetail(): void
    {
        $server = TCP\listen('127.0.0.1', 0);
        $localAddress = $server->getLocalAddress();

        $serverFuture = Async\run(static function () use ($server): void {
            $client = $server->accept();

            $lengthData = $client->readFixedSize(2);
            /** @var positive-int $length */
            $length = new Reader($lengthData)->u16();
            $query = $client->readFixedSize($length);
            $id = new Reader($query)->u16();

            $wrongId = ($id + 42) & 0xFFFF;

            $answerName = "\x07example\x03com\x00";
            $responsePacket = new Writer()
                ->u16($wrongId)
                ->u16(0x8180)
                ->u16(1)
                ->u16(1)
                ->u16(0)
                ->u16(0)
                ->bytes($answerName)
                ->u16(1)
                ->u16(1)
                ->bytes($answerName)
                ->u16(1)
                ->u16(1)
                ->u32(300)
                ->u16(4)
                ->u8(10)
                ->u8(0)
                ->u8(0)
                ->u8(1)
                ->toString();

            $tcpResponse = new Writer()
                ->u16(Str\Byte\length($responsePacket))
                ->bytes($responsePacket)
                ->toString();

            $client->writeAll($tcpResponse);
            $client->close();
            $server->close();
        });

        $resolver = new TCPResolver(host: $localAddress->host, port: $localAddress->port ?? 0);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/does not match query ID/');

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $serverFuture->await();
        }
    }
}
