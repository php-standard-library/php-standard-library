<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Psl\OS;
use Psl\UDP;

use function fclose;
use function fwrite;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class SocketTest extends TestCase
{
    public function testBindAndGetLocalAddress(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $address = $socket->getLocalAddress();
        static::assertSame('127.0.0.1', $address->host);
        static::assertGreaterThan(0, $address->port);
        static::assertSame(Network\SocketScheme::Udp, $address->scheme);
        $socket->close();
    }

    public function testBindWithOptions(): void
    {
        $socket = UDP\Socket::bind(
            '127.0.0.1',
            0,
            new UDP\BindConfiguration(reuseAddress: false, reusePort: false, broadcast: false),
        );
        $address = $socket->getLocalAddress();
        static::assertSame('127.0.0.1', $address->host);
        static::assertGreaterThan(0, $address->port);
        $socket->close();
    }

    public function testBindWithSpecificPort(): void
    {
        $temp = UDP\Socket::bind('127.0.0.1', 0);
        $port = $temp->getLocalAddress()->port;
        $temp->close();

        $socket = UDP\Socket::bind('127.0.0.1', $port);
        static::assertSame($port, $socket->getLocalAddress()->port);
        $socket->close();
    }

    public function testBindFailsWithInvalidAddress(): void
    {
        $this->expectException(Network\Exception\RuntimeException::class);

        UDP\Socket::bind('999.999.999.999', 0);
    }

    public function testSendToAndReceiveFrom(): void
    {
        $receiver = UDP\Socket::bind('127.0.0.1', 0);
        $sender = UDP\Socket::bind('127.0.0.1', 0);

        $sender->sendTo('hello', $receiver->getLocalAddress());

        [$data, $from] = $receiver->receiveFrom(1024);
        static::assertSame('hello', $data);
        static::assertSame('127.0.0.1', $from->host);
        static::assertSame($sender->getLocalAddress()->port, $from->port);

        $sender->close();
        $receiver->close();
    }

    public function testSendToReturnsByteCount(): void
    {
        $receiver = UDP\Socket::bind('127.0.0.1', 0);
        $sender = UDP\Socket::bind('127.0.0.1', 0);

        $bytesSent = $sender->sendTo('test-data', $receiver->getLocalAddress());
        static::assertSame(9, $bytesSent);

        [$data] = $receiver->receiveFrom(1024);
        static::assertSame('test-data', $data);

        $sender->close();
        $receiver->close();
    }

    public function testPeekFromDoesNotConsumeData(): void
    {
        $receiver = UDP\Socket::bind('127.0.0.1', 0);
        $sender = UDP\Socket::bind('127.0.0.1', 0);

        $sender->sendTo('peek-test', $receiver->getLocalAddress());

        [$data, $from] = $receiver->peekFrom(1024);
        static::assertSame('peek-test', $data);
        static::assertSame('127.0.0.1', $from->host);
        static::assertSame($sender->getLocalAddress()->port, $from->port);

        [$data2] = $receiver->receiveFrom(1024);
        static::assertSame('peek-test', $data2);

        $sender->close();
        $receiver->close();
    }

    public function testSendToWithTimeout(): void
    {
        $receiver = UDP\Socket::bind('127.0.0.1', 0);
        $sender = UDP\Socket::bind('127.0.0.1', 0);

        $bytesSent = $sender->sendTo(
            'timeout-test',
            $receiver->getLocalAddress(),
            new Async\TimeoutCancellationToken(Duration::seconds(5)),
        );
        static::assertSame(12, $bytesSent);

        [$data] = $receiver->receiveFrom(1024);
        static::assertSame('timeout-test', $data);

        $sender->close();
        $receiver->close();
    }

    public function testReceiveFromTimeout(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        try {
            $socket->receiveFrom(1024, new Async\TimeoutCancellationToken(Duration::milliseconds(50)));
        } finally {
            $socket->close();
        }
    }

    public function testPeekFromTimeout(): void
    {
        $this->expectException(Async\Exception\CancelledException::class);

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        try {
            $socket->peekFrom(1024, new Async\TimeoutCancellationToken(Duration::milliseconds(50)));
        } finally {
            $socket->close();
        }
    }

    public function testSendToOnClosedSocketThrows(): void
    {
        $this->expectException(IO\Exception\AlreadyClosedException::class);

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $socket->close();
        $socket->sendTo('data', Network\Address::udp('127.0.0.1', 9999));
    }

    public function testReceiveFromOnClosedSocketThrows(): void
    {
        $this->expectException(IO\Exception\AlreadyClosedException::class);

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $socket->close();
        $socket->receiveFrom(1024);
    }

    public function testPeekFromOnClosedSocketThrows(): void
    {
        $this->expectException(IO\Exception\AlreadyClosedException::class);

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $socket->close();
        $socket->peekFrom(1024);
    }

    public function testConnectOnClosedSocketThrows(): void
    {
        $this->expectException(IO\Exception\AlreadyClosedException::class);

        Async\run(static function (): void {
            $socket = UDP\Socket::bind('127.0.0.1', 0);
            $socket->close();
            $socket->connect('127.0.0.1', 9999);
        })->await();
    }

    public function testPayloadSizeValidationOnSendTo(): void
    {
        $this->expectException(Network\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum size');

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        try {
            $socket->sendTo(str_repeat('x', 65_508), Network\Address::udp('127.0.0.1', 9999));
        } finally {
            $socket->close();
        }
    }

    public function testPayloadExactlyAtMaxDoesNotThrowValidation(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $data = str_repeat('x', 65_507);
        $threwInvalidArgument = false;
        try {
            $socket->sendTo($data, Network\Address::udp('127.0.0.1', 9999));
        } catch (Network\Exception\InvalidArgumentException) {
            $threwInvalidArgument = true;
        } catch (Network\Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause
        } finally {
            $socket->close();
        }

        static::assertFalse($threwInvalidArgument);
    }

    public function testConnectReturnsConnectedSocket(): void
    {
        $server = UDP\Socket::bind('127.0.0.1', 0);
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $serverAddr = $server->getLocalAddress();

        $connected = $socket->connect($serverAddr->host, $serverAddr->port);

        static::assertInstanceOf(UDP\ConnectedSocket::class, $connected);
        static::assertSame($serverAddr->host, $connected->getPeerAddress()->host);
        static::assertSame($serverAddr->port, $connected->getPeerAddress()->port);

        $connected->close();
        $server->close();
    }

    public function testConnectInvalidatesOriginalSocket(): void
    {
        $this->expectException(IO\Exception\AlreadyClosedException::class);

        $server = UDP\Socket::bind('127.0.0.1', 0);
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $serverAddr = $server->getLocalAddress();

        $connected = $socket->connect($serverAddr->host, $serverAddr->port);

        try {
            $socket->sendTo('data', Network\Address::udp('127.0.0.1', 9999));
        } finally {
            $connected->close();
            $server->close();
        }
    }

    public function testGetStreamReturnsResource(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        static::assertIsResource($socket->getStream());
        $socket->close();
    }

    public function testGetStreamReturnsNullAfterClose(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $socket->close();
        static::assertNull($socket->getStream());
    }

    public function testCloseThrowsOnSubsequentUse(): void
    {
        $this->expectException(IO\Exception\AlreadyClosedException::class);

        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $socket->close();
        $socket->sendTo('data', Network\Address::udp('127.0.0.1', 9999));
    }

    public function testIsClosedOnSocket(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        static::assertFalse($socket->isClosed());
        $socket->close();
        static::assertTrue($socket->isClosed());
    }

    public function testDoubleCloseDoesNotThrow(): void
    {
        $socket = UDP\Socket::bind('127.0.0.1', 0);
        $socket->close();
        $socket->close();
        static::assertNull($socket->getStream());
    }

    public function testParseAddressEmptyString(): void
    {
        $result = UDP\Internal\parse_address('');
        static::assertSame('0.0.0.0', $result->host);
        static::assertSame(0, $result->port);
    }

    public function testParseAddressIpv6WithPort(): void
    {
        $result = UDP\Internal\parse_address('[::1]:8080');
        static::assertSame('::1', $result->host);
        static::assertSame(8080, $result->port);
    }

    public function testParseAddressIpv6WithoutPort(): void
    {
        $result = UDP\Internal\parse_address('[::1]');
        static::assertSame('::1', $result->host);
        static::assertSame(0, $result->port);
    }

    public function testParseAddressIpv6EmptyHost(): void
    {
        $result = UDP\Internal\parse_address('[]:8080');
        static::assertSame('::', $result->host);
        static::assertSame(8080, $result->port);
    }

    public function testParseAddressIpv6NoBracketClose(): void
    {
        $result = UDP\Internal\parse_address('[::1');
        static::assertSame('[::1', $result->host);
        static::assertSame(0, $result->port);
    }

    public function testParseAddressIpv4WithPort(): void
    {
        $result = UDP\Internal\parse_address('127.0.0.1:8080');
        static::assertSame('127.0.0.1', $result->host);
        static::assertSame(8080, $result->port);
    }

    public function testParseAddressIpv4WithoutColon(): void
    {
        $result = UDP\Internal\parse_address('192.168.1.1');
        static::assertSame('192.168.1.1', $result->host);
        static::assertSame(0, $result->port);
    }

    public function testParseAddressEmptyHostWithPort(): void
    {
        $result = UDP\Internal\parse_address(':8080');
        static::assertSame('0.0.0.0', $result->host);
        static::assertSame(8080, $result->port);
    }

    public function testParseAddressIpv6WithEmptyPort(): void
    {
        $result = UDP\Internal\parse_address('[::1]:');
        static::assertSame('::1', $result->host);
        static::assertSame(0, $result->port);
    }

    public function testParseAddressIpv4InvalidPort(): void
    {
        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Invalid port number');

        UDP\Internal\parse_address('127.0.0.1:99999');
    }

    public function testParseAddressIpv6InvalidPort(): void
    {
        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Invalid port number');

        UDP\Internal\parse_address('[::1]:99999');
    }

    public function testWaitWritableTimeout(): void
    {
        if (OS\is_windows()) {
            static::markTestSkipped('stream_socket_pair with STREAM_PF_UNIX not available on Windows');
        }

        $this->expectException(Async\Exception\CancelledException::class);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        $chunk = str_repeat('x', 65_536);
        while (@fwrite($pair[0], $chunk) > 0) {
            // @mago-expect lint:no-empty-loop
        }

        try {
            new UDP\Internal\PendingWaits()->writable(
                $pair[0],
                new Async\TimeoutCancellationToken(Duration::milliseconds(50)),
            );
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }
}
