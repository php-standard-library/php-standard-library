<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Binary\Reader;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DNS\Exception\NetworkException;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\RecordType;
use Psl\DNS\ResponseCode;
use Psl\DNS\UDPResolver;
use Psl\Network;
use Psl\UDP\Socket;
use ReflectionClass;

use function pack;
use function str_repeat;
use function substr;

final class UDPResolverTest extends TestCase
{
    public function testConstructorAcceptsHostAndPort(): void
    {
        $resolver = new UDPResolver(host: '127.0.0.1', port: 5353);

        static::assertInstanceOf(UDPResolver::class, $resolver);
    }

    public function testDefaultPortIs53(): void
    {
        $resolver = new UDPResolver(host: '127.0.0.1');

        $reflection = new ReflectionClass($resolver);
        $port = $reflection->getProperty('port')->getValue($resolver);

        static::assertSame(53, $port);
    }

    public function testDefaultDnssecIsFalse(): void
    {
        $resolver = new UDPResolver(host: '127.0.0.1');

        $reflection = new ReflectionClass($resolver);
        $dnssec = $reflection->getProperty('dnssec')->getValue($resolver);

        static::assertFalse($dnssec);
    }

    public function testCustomDnssecIsRespected(): void
    {
        $resolver = new UDPResolver(host: '127.0.0.1', dnssec: true);

        $reflection = new ReflectionClass($resolver);
        $dnssec = $reflection->getProperty('dnssec')->getValue($resolver);

        static::assertTrue($dnssec);
    }

    public function testInvalidHostThrowsNetworkException(): void
    {
        $resolver = new UDPResolver(host: '');

        $this->expectException(NetworkException::class);

        $resolver->query('example.com', RecordType::A);
    }

    public function testInvalidHostThrowsNetworkExceptionNotOtherType(): void
    {
        $resolver = new UDPResolver(host: '');

        $this->expectException(NetworkException::class);

        $resolver->query('example.com', RecordType::A);
    }

    public function testSuccessfulQueryReturnsValidResponse(): void
    {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();

        $serverFuture = Async\run(static function () use ($socket): void {
            [$data, $peer] = $socket->receiveFrom(512);
            $id = new Reader($data)->u16();

            $answerName = "\x07example\x03com\x00";
            $response = new Writer()
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

            $socket->sendTo($response, $peer);
        });

        /** @var int<0, 65535> $port */
        $port = $address->port;
        $resolver = new UDPResolver(host: $address->host, port: $port);

        try {
            $response = $resolver->query('example.com', RecordType::A);
            static::assertSame(ResponseCode::NoError, $response->code);
            static::assertCount(1, $response->answers);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    public function testSocketIsClosedAfterSuccessfulQuery(): void
    {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();

        $serverFuture = Async\run(static function () use ($socket): void {
            [$data, $peer] = $socket->receiveFrom(512);
            $id = new Reader($data)->u16();

            $answerName = "\x07example\x03com\x00";
            $response = new Writer()
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

            $socket->sendTo($response, $peer);
        });

        /** @var int<0, 65535> $port */
        $port = $address->port;
        $resolver = new UDPResolver(host: $address->host, port: $port);

        try {
            $response = $resolver->query('example.com', RecordType::A);
            static::assertSame(ResponseCode::NoError, $response->code);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    public function testSourceAddressVerificationIncludesPort(): void
    {
        $targetServer = Socket::bind('127.0.0.1', 0);
        $targetAddress = $targetServer->getLocalAddress();

        $spoofServer = Socket::bind('127.0.0.1', 0);
        $spoofAddress = $spoofServer->getLocalAddress();

        $serverFuture = Async\run(static function () use ($targetServer, $spoofServer): void {
            [$query, $clientPeer] = $targetServer->receiveFrom(512);
            $reader = new Reader($query);
            $id = $reader->u16();

            $responsePacket = new Writer()
                ->u16($id)
                ->u16(0x8180)
                ->u16(0)
                ->u16(0)
                ->u16(0)
                ->u16(0)
                ->toString();

            $spoofServer->sendTo($responsePacket, $clientPeer);
            $targetServer->close();
            $spoofServer->close();
        });

        /** @var int<0, 65535> $targetPort */
        $targetPort = $targetAddress->port;
        $resolver = new UDPResolver(host: $targetAddress->host, port: $targetPort);

        try {
            $resolver->query('test.example', RecordType::A);
            static::fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $spoofPort = (string) ($spoofAddress->port ?? 0);
            static::assertStringContainsString($spoofPort, $e->getMessage());
            static::assertStringContainsString('DNS response received from', $e->getMessage());
        } finally {
            $serverFuture->await();
        }
    }

    public function testIdMismatchThrowsProtocolException(): void
    {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();

        $serverFuture = Async\run(static function () use ($socket): void {
            [$data, $peer] = $socket->receiveFrom(512);
            $id = new Reader($data)->u16();
            $wrongId = ($id + 1) & 0xFFFF;

            $answerName = "\x07example\x03com\x00";
            $response = new Writer()
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

            $socket->sendTo($response, $peer);
        });

        /** @var int<0, 65535> $port */
        $port = $address->port;
        $resolver = new UDPResolver(host: $address->host, port: $port);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/does not match query ID/');

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    public function testTruncatedResponseThrowsProtocolException(): void
    {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();

        $serverFuture = Async\run(static function () use ($socket): void {
            [$data, $peer] = $socket->receiveFrom(512);
            $id = new Reader($data)->u16();

            $answerName = "\x07example\x03com\x00";
            $response = new Writer()
                ->u16($id)
                ->u16(0x8380) // response + TC flag (0x0200) + RD (0x0100)
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

            $socket->sendTo($response, $peer);
        });

        /** @var int<0, 65535> $port */
        $port = $address->port;
        $resolver = new UDPResolver(host: $address->host, port: $port);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/truncat/i');

        try {
            $resolver->query('example.com', RecordType::A);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    public function testFormerrWithoutQuestionIsReturned(): void
    {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(2));

        $serverFuture = Async\run(static function () use ($socket, $cancellation): void {
            [$query, $peer] = $socket->receiveFrom(512, $cancellation);
            $id = new Reader($query)->u16();
            $socket->sendTo(pack('n6', $id, 0x8181, 0, 0, 0, 0), $peer, $cancellation);
        });

        $resolver = new UDPResolver(host: $address->host, port: $address->port ?? 0);
        try {
            $response = $resolver->query('test.example', RecordType::A, $cancellation);
            static::assertSame(ResponseCode::FormatError, $response->code);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    public function testWrongIdIsRejectedBeforeMalformedRecordsAreDecoded(): void
    {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(2));

        $serverFuture = Async\run(static function () use ($socket, $cancellation): void {
            [$query, $peer] = $socket->receiveFrom(512, $cancellation);
            $id = new Reader($query)->u16();
            $response =
                pack('n6', $id ^ 0xFFFF, 0x8180, 1, 1, 0, 0) . Encoder::encodeName('example.com') . pack('nn', 1, 1);
            $socket->sendTo($response, $peer, $cancellation);
        });

        $resolver = new UDPResolver(host: $address->host, port: $address->port ?? 0);
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('does not match query ID');

        try {
            $resolver->query('example.com', RecordType::A, $cancellation);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    #[DataProvider('provideQuestionMismatches')]
    public function testMismatchedQuestionThrowsProtocolException(
        string $name,
        int $type,
        int $class,
        int $count,
        bool $malformed,
    ): void {
        $socket = Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(5));

        $serverFuture = Async\run(static function () use (
            $socket,
            $name,
            $type,
            $class,
            $count,
            $malformed,
            $cancellation,
        ): void {
            [$data, $peer] = $socket->receiveFrom(512, $cancellation);
            $id = new Reader($data)->u16();
            $question = Encoder::encodeName($name) . pack('nn', $type, $class);
            $answer = Encoder::encodeName('example.com') . pack('nnNn', 1, 1, 300, 4);
            $spoofed =
                pack('n6', $id, 0x8180, $count, 1, 0, 0) . str_repeat($question, $count) . $answer . "\x0A\x00\x00\x01";
            if ($malformed) {
                $spoofed = substr($spoofed, 0, -4);
            }

            $socket->sendTo($spoofed, $peer, $cancellation);
        });

        /** @var int<0, 65535> $port */
        $port = $address->port;
        $resolver = new UDPResolver(host: $address->host, port: $port);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('question does not match');

        try {
            $resolver->query('example.com.', RecordType::A, $cancellation);
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    /**
     * @return array<string, array{string, int, int, int, bool}>
     */
    public static function provideQuestionMismatches(): array
    {
        return [
            'wrong name' => ['attacker.example', 1, 1, 1, false],
            'wrong type' => ['example.com', 28, 1, 1, false],
            'wrong class' => ['example.com', 1, 3, 1, false],
            'missing question' => ['example.com', 1, 1, 0, false],
            'extra question' => ['example.com', 1, 1, 2, false],
            'wrong name with malformed answer' => ['attacker.example', 1, 1, 1, true],
        ];
    }

    #[DataProvider('provideIpv6Hosts')]
    public function testIpv6Nameserver(string $host): void
    {
        $socket = Socket::bind('[::1]', 0);
        $address = $socket->getLocalAddress();
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(5));

        $serverFuture = Async\run(static function () use ($socket, $cancellation): void {
            [$data, $peer] = $socket->receiveFrom(512, $cancellation);
            $id = new Reader($data)->u16();
            $response = pack('n6', $id, 0x8180, 1, 0, 0, 0) . Encoder::encodeName('example.com') . pack('nn', 1, 1);
            /** @var int<0, 65535> $port */
            $port = $peer->port;
            $socket->sendTo($response, Network\Address::udp('[' . $peer->host . ']', $port), $cancellation);
        });

        /** @var int<0, 65535> $port */
        $port = $address->port;
        $resolver = new UDPResolver(host: $host, port: $port);

        try {
            static::assertSame(
                ResponseCode::NoError,
                $resolver->query('example.com', RecordType::A, $cancellation)->code,
            );
        } finally {
            $socket->close();
            $serverFuture->await();
        }
    }

    /**
     * @return list<array{non-empty-string}>
     */
    public static function provideIpv6Hosts(): array
    {
        return [['::1'], ['[::1]'], ['0:0:0:0:0:0:0:1']];
    }
}
