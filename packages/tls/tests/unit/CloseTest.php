<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\TCP;
use Psl\TLS;

use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function intdiv;
use function str_repeat;
use function stream_select;
use function strlen;

/**
 * Closing a TLS stream ends the connection, even when the peer sent bytes that were never read.
 */
final class CloseTest extends TestCase
{
    /**
     * More than the socket buffers hold, so that the end of it is still on its way when the writer closes.
     */
    private const int PAYLOAD_SIZE = 1024 * 1024;

    public function testCloseWithUnreadBytesEndsTheConnection(): void
    {
        [$client, $server] = self::connectPair();

        // A TLS 1.3 server sends session tickets right after the handshake; this client never reads them.
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
            } catch (IO\Exception\RuntimeException $exception) {
                return [hash_final($hash), $received, $exception->getMessage()];
            }
        });

        $payload = str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));
        $client->writeAll($payload);
        // Unread bytes made the kernel reset the connection instead of ending it: it dropped what was still on
        // its way, close_notify included, and the server saw an error, or nothing at all, instead of the end.
        $client->close();

        [$digest, $received, $end] = $reader->await();
        $server->close();

        static::assertSame(self::PAYLOAD_SIZE, $received);
        static::assertSame(hash('xxh128', $payload), $digest);
        static::assertSame('end of stream', $end);
    }

    /**
     * @return array{TLS\StreamInterface, TLS\StreamInterface}
     */
    private static function connectPair(): array
    {
        $listener = new TLS\Listener(
            TCP\listen('127.0.0.1', 0),
            TLS\ServerConfiguration::create(TLS\Certificate::create(
                __DIR__ . '/../../fixture/certs/server.crt',
                __DIR__ . '/../../fixture/certs/server.key',
            )),
        );
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            [$server, $client] = Async\concurrently([
                static fn(): TLS\StreamInterface => $listener->accept($deadline),
                static fn(): TLS\StreamInterface => TLS\connect(
                    '127.0.0.1',
                    $listener->getLocalAddress()->port,
                    TLS\ClientConfiguration::default()->withPeerVerification(false),
                    $deadline,
                ),
            ]);
        } finally {
            $listener->close();
        }

        return [$client, $server];
    }
}
