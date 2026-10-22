<?php

declare(strict_types=1);

namespace Psl\UDP\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\OS;
use Psl\UDP;

use function extension_loaded;

use const SOCKET_ECONNREFUSED;

/**
 * A receive on a connected UDP socket whose peer's port is unreachable reports why it failed, and leaves the socket
 * usable.
 */
final class ConnectedReceiveErrorTest extends TestCase
{
    /**
     * The most bytes a receive in these tests takes.
     */
    private const int MAX_BYTES = 100;

    /**
     * How long a receive may wait before the test counts it as stuck.
     */
    private const int DEADLINE_MILLISECONDS = 2_000;

    /**
     * With ext-sockets, a receive after the peer's port answered with an ICMP port-unreachable message fails with
     * ECONNREFUSED as its code, and the next receive gets the next datagram.
     */
    public function testReceiveAfterAnUnreachablePortFailsWithTheErrno(): void
    {
        self::requireTheSocketsPath();

        [$connected, $port] = self::connectToAClosedPort();

        try {
            $connected->send('ping');
            $failure = self::failure(static fn(): string => $connected->receive(self::MAX_BYTES, self::deadline()));

            static::assertSame(SOCKET_ECONNREFUSED, $failure->getCode());
            static::assertStringContainsString('Failed to receive UDP datagram: ', $failure->getMessage());

            $server = UDP\Socket::bind('127.0.0.1', $port);
            $server->sendTo('pong', $connected->getLocalAddress());
            static::assertSame('pong', $connected->receive(self::MAX_BYTES, self::deadline()));
            $server->close();
        } finally {
            $connected->close();
        }
    }

    /**
     * With ext-sockets, a peek after the peer's port answered with an ICMP port-unreachable message fails with
     * ECONNREFUSED as its code.
     */
    public function testPeekAfterAnUnreachablePortFailsWithTheErrno(): void
    {
        self::requireTheSocketsPath();

        [$connected] = self::connectToAClosedPort();

        try {
            $connected->send('ping');
            $failure = self::failure(static fn(): string => $connected->peek(self::MAX_BYTES, self::deadline()));

            static::assertSame(SOCKET_ECONNREFUSED, $failure->getCode());
            static::assertStringContainsString('Failed to peek UDP datagram: ', $failure->getMessage());
        } finally {
            $connected->close();
        }
    }

    /**
     * Skip the test unless receives go through ext-sockets: on POSIX systems with the extension.
     */
    private static function requireTheSocketsPath(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('The sockets extension is required.');
        }

        if (OS\is_windows()) {
            static::markTestSkipped('Windows receives keep the stream path.');
        }
    }

    /**
     * Connect to a port that nothing listens on, and return the socket and the port.
     *
     * @return array{UDP\ConnectedSocket, int<0, 65535>}
     */
    private static function connectToAClosedPort(): array
    {
        $server = UDP\Socket::bind('127.0.0.1');
        $port = $server->getLocalAddress()->port;
        static::assertNotNull($port);
        $server->close();

        return [UDP\connect('127.0.0.1', $port), $port];
    }

    /**
     * Run the given receive, and return the exception it failed with.
     *
     * @param (Closure(): string) $receive
     */
    private static function failure(Closure $receive): Network\Exception\RuntimeException
    {
        try {
            $receive();
        } catch (Network\Exception\RuntimeException $e) {
            return $e;
        }

        static::fail('The receive did not fail.');
    }

    /**
     * A token that ends a receive stuck for longer than the test allows.
     */
    private static function deadline(): Async\CancellationTokenInterface
    {
        return new Async\TimeoutCancellationToken(Duration::milliseconds(self::DEADLINE_MILLISECONDS));
    }
}
