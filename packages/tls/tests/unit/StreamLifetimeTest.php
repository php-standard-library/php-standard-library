<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;
use Psl\TLS;
use Revolt\EventLoop;
use WeakReference;

use function array_diff;
use function array_values;
use function gc_collect_cycles;
use function hash;
use function intdiv;
use function is_resource;
use function str_repeat;
use function substr;

/**
 * A TLS stream that is no longer referenced is destroyed, and closes its connection, at once; one that a
 * write is waiting on stays alive.
 */
final class StreamLifetimeTest extends TestCase
{
    /**
     * More than the kernel buffers of a loopback connection hold, so that writing it has to wait for the peer.
     */
    private const int PAYLOAD_SIZE = 16 * 1024 * 1024;

    public function testDroppedStreamIsDestroyed(): void
    {
        $callbacks = EventLoop::getIdentifiers();

        [$client, $server] = self::connectPair();
        $clientResource = $client->getStream();
        $serverResource = $server->getStream();
        $clientReference = WeakReference::create($client);
        $serverReference = WeakReference::create($server);
        unset($client, $server);

        // The write sequence's callback referenced the stream, so only the cycle collector could free it, and
        // the connection stayed open until it ran.
        static::assertFalse(is_resource($clientResource));
        static::assertFalse(is_resource($serverResource));
        static::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $callbacks)));
        static::assertNull($clientReference->get());
        static::assertNull($serverReference->get());
    }

    public function testDroppedStreamIsDestroyedAfterWaiting(): void
    {
        $callbacks = EventLoop::getIdentifiers();

        [$client, $server] = self::connectPair();
        $clientResource = $client->getStream();
        $serverResource = $server->getStream();
        $payload = self::payload();
        $deadline = self::timeout();

        // The payload does not fit in the socket buffers, so writeAll() waits for the server to read.
        [, $received] = Async\concurrently([
            static fn() => $client->writeAll($payload, $deadline),
            static fn(): string => $server->readFixedSize(self::PAYLOAD_SIZE, $deadline),
        ]);

        static::assertSame(hash('xxh128', $payload), hash('xxh128', $received));

        $clientReference = WeakReference::create($client);
        unset($client, $server, $deadline);
        // Let the event loop drop the values it last handed over.
        Async\later();

        static::assertFalse(is_resource($clientResource));
        static::assertFalse(is_resource($serverResource));
        static::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $callbacks)));
        static::assertNull($clientReference->get());
    }

    public function testWaitingWritesKeepStreamAlive(): void
    {
        [$client, $server] = self::connectPair();
        $payload = self::payload();

        // The writers keep the stream until the server has read everything: once they let go of it, it is
        // closed, and closing it before the last bytes arrive could reset the connection.
        $read = new Async\Deferred();
        $hold = $read->getAwaitable();

        // writeAll() waits for the server to read; write() waits in the sequence behind it.
        $writeAll = Async\run(static function () use ($client, $payload, $hold): void {
            $client->writeAll($payload, self::timeout());
            $hold->await(self::timeout());
        });
        $write = Async\run(static function () use ($client, $hold): int {
            $written = $client->write('pong', self::timeout());
            $hold->await(self::timeout());

            return $written;
        });
        unset($client);

        // Once both writes wait, the stream is only referenced by the waiting fibers. The event loop must keep
        // them, and the stream, alive until the socket becomes writable.
        Async\later();
        gc_collect_cycles();

        $received = $server->readFixedSize(self::PAYLOAD_SIZE + 4, self::timeout());
        $read->complete(null);

        $writeAll->await(self::timeout());
        static::assertSame(4, $write->await(self::timeout()));
        static::assertSame(hash('xxh128', $payload), hash('xxh128', substr($received, 0, self::PAYLOAD_SIZE)));
        static::assertSame('pong', substr($received, self::PAYLOAD_SIZE));

        $server->close();
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
        $deadline = self::timeout();

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

        // The event loop keeps the last value it resumed this fiber with until it runs again, and that value
        // references one of the streams. Let it run, so that only the caller references them.
        Async\later();

        return [$client, $server];
    }

    private static function payload(): string
    {
        return str_repeat('0123456789abcdef', intdiv(self::PAYLOAD_SIZE, 16));
    }

    /**
     * Bounds each wait, so that a fiber destroyed while it waits fails the test instead of hanging it.
     */
    private static function timeout(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(10));
    }
}
