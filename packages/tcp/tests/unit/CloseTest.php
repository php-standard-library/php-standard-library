<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;
use RuntimeException;

use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function intdiv;
use function str_repeat;
use function stream_select;
use function strlen;

/**
 * Closing a TCP stream ends the connection, even when the peer sent bytes that were never read.
 */
final class CloseTest extends TestCase
{
    /**
     * More than the socket buffers hold, so that the end of it is still on its way when the writer closes.
     */
    private const int PAYLOAD_SIZE = 1024 * 1024;

    public function testCloseWithUnreadBytesEndsTheConnection(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);

        try {
            [$server, $client] = Async\concurrently([
                $listener->accept(...),
                static fn(): TCP\StreamInterface => TCP\connect('127.0.0.1', $listener->getLocalAddress()->port),
            ]);
        } finally {
            $listener->close();
        }

        // Something the client never reads, like a greeting or a pipelined request.
        $server->writeAll("never read by the client\r\n");
        $read = [$client->getStream()];
        $write = [];
        $except = [];
        static::assertSame(1, stream_select($read, $write, $except, 1), 'The client has no unread bytes.');

        // The server reads slowly, so that the end of the payload is still in the client's socket buffer when the
        // client closes.
        $reader = Async\run(static function () use ($server): array {
            $hash = hash_init('xxh128');
            $received = 0;

            try {
                while (true) {
                    $chunk = $server->read(16_384, new Async\TimeoutCancellationToken(Duration::seconds(2)));
                    if ('' === $chunk) {
                        return [hash_final($hash), $received, 'end of stream'];
                    }

                    hash_update($hash, $chunk);
                    $received += strlen($chunk);
                    Async\later();
                }
            } catch (Async\Exception\CancelledException) {
                return [hash_final($hash), $received, 'timed out'];
            } catch (RuntimeException $exception) {
                return [hash_final($hash), $received, $exception->getMessage()];
            }
        });

        $payload = str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));
        $client->writeAll($payload);
        // Unread bytes made the kernel reset the connection instead of ending it: it dropped what was still on
        // its way, and the server saw an error instead of the end of the stream.
        $client->close();

        [$digest, $received, $end] = $reader->await();
        $server->close();

        static::assertSame(self::PAYLOAD_SIZE, $received);
        static::assertSame(hash('xxh128', $payload), $digest);
        static::assertSame('end of stream', $end);
    }
}
