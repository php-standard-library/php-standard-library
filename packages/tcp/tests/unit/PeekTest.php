<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\TCP;

use function fclose;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function usleep;

/**
 * A waiting peek on a TCP stream waits again when another reader took the bytes it woke up for, and fails, without
 * waiting again, when the connection was reset.
 */
final class PeekTest extends TestCase
{
    /**
     * How long a peek may wait before the test counts it as stuck.
     */
    private const int DEADLINE_MILLISECONDS = 5_000;

    /**
     * How long the reset takes to reach the client over loopback, generously.
     */
    private const int RESET_DELAY_MICROSECONDS = 50_000;

    /**
     * A peek that wakes up after a read that waited before it took the bytes waits for the next bytes, instead of
     * failing.
     */
    public function testPeekWaitsAgainWhenAnEarlierReadTookTheBytes(): void
    {
        $listener = TCP\listen('127.0.0.1');

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port);
            $server = $listener->accept();

            $read = Async\run(static fn(): string => $client->read(5));
            $peek = Async\run(static fn(): string => $client->peek(5));
            Async\later();

            $server->writeAll('hello');
            static::assertSame('hello', $read->await());

            // Let the peek wake up and find nothing, before the next bytes arrive.
            Async\later();
            $server->writeAll('world');
            static::assertSame('world', $peek->await());
            static::assertSame('world', $client->read(5));

            $client->close();
            $server->close();
        } finally {
            $listener->close();
        }
    }

    /**
     * A peek on a connection the peer reset fails, instead of waiting again for a socket that stays readable.
     */
    public function testPeekFailsWhenTheConnectionIsReset(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        static::assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        static::assertNotFalse($address);

        try {
            $client = TCP\connect('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1));
            $connection = stream_socket_accept($server, 1.0);
            static::assertNotFalse($connection);

            // Closing a socket that holds unread bytes resets the connection.
            $client->writeAll('unread');
            usleep(self::RESET_DELAY_MICROSECONDS);
            fclose($connection);

            $deadline = new Async\TimeoutCancellationToken(Duration::milliseconds(self::DEADLINE_MILLISECONDS));

            $this->expectException(IO\Exception\RuntimeException::class);
            $this->expectExceptionMessage('Failed to peek data from stream.');

            $client->peek(5, $deadline);
        } finally {
            fclose($server);
        }
    }
}
