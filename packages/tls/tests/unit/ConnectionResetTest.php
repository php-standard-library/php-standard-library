<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;

use function fclose;

/**
 * A connection reset on a TLS stream is a read failure, as it is on a plain socket, never the end of the data.
 */
final class ConnectionResetTest extends TestCase
{
    public function testConnectionResetWhileWaitingIsAReadFailure(): void
    {
        [$client, $server, $relay] = self::connectThroughRelay();

        // The relay never reads this. Closing its socket with unread bytes resets the connection, without the
        // close_notify that closing a TLS stream sends first, as a crashed peer or a middlebox would.
        $client->writeAll('unread');
        Async\sleep(Duration::milliseconds(10));

        $read = Async\run(static fn(): string => $client->read(
            null,
            new Async\TimeoutCancellationToken(Duration::seconds(5)),
        ));
        Async\sleep(Duration::milliseconds(10));
        fclose($relay->getStream());

        try {
            $chunk = $read->await();
            static::fail('The read returned "' . $chunk . '" instead of failing.');
        } catch (IO\Exception\RuntimeException $exception) {
            $failure = $exception->getMessage();
        }

        // The failure is kept, as on a plain socket: every later read throws it again.
        try {
            $chunk = $client->read();
            static::fail('The second read returned "' . $chunk . '" instead of failing.');
        } catch (IO\Exception\RuntimeException $exception) {
            static::assertSame($failure, $exception->getMessage());
        }

        $client->close();
        $server->close();
        $relay->close();
    }

    /**
     * Connect a TLS client to a TLS server through a plain TCP relay, then stop the relay.
     *
     * @return array{TLS\StreamInterface, TLS\StreamInterface, Network\StreamInterface} The client, the server, and
     *                                                                                  the relay's end of the
     *                                                                                  client's connection.
     */
    private static function connectThroughRelay(): array
    {
        $listener = new TLS\Listener(
            TCP\listen('127.0.0.1', 0),
            TLS\ServerConfiguration::create(TLS\Certificate::create(
                __DIR__ . '/../../fixture/certs/server.crt',
                __DIR__ . '/../../fixture/certs/server.key',
            )),
        );
        $relayListener = TCP\listen('127.0.0.1', 0);
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));
        $stop = new Async\SignalCancellationToken();
        $forward = static function (Network\StreamInterface $from, Network\StreamInterface $to) use ($stop): void {
            try {
                while (true) {
                    $chunk = $from->read(null, $stop);
                    if ('' === $chunk && $from->reachedEndOfDataSource()) {
                        return;
                    }

                    $to->writeAll($chunk, $stop);
                }
            } catch (Async\Exception\CancelledException) {
                return;
            }
        };

        try {
            [[$relay, $upstream, $forwarders], $server, $client] = Async\concurrently([
                static function () use ($relayListener, $listener, $forward, $deadline): array {
                    $relay = $relayListener->accept($deadline);
                    $upstream = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port);
                    $forwarders = [
                        Async\run(static fn() => $forward($relay, $upstream)),
                        Async\run(static fn() => $forward($upstream, $relay)),
                    ];

                    return [$relay, $upstream, $forwarders];
                },
                static fn(): TLS\StreamInterface => $listener->accept($deadline),
                static fn(): TLS\StreamInterface => TLS\connect(
                    '127.0.0.1',
                    $relayListener->getLocalAddress()->port,
                    TLS\ClientConfiguration::default()->withPeerVerification(false),
                    $deadline,
                ),
            ]);
        } finally {
            $listener->close();
            $relayListener->close();
        }

        // The client reads everything the relay sends it before the reset: macOS can ignore a reset that arrives
        // while the receiver still owes an acknowledgment for the bytes in front of it.
        $client->writeAll('ping');
        static::assertSame('ping', $server->readFixedSize(4, $deadline));
        $server->writeAll('pong');
        static::assertSame('pong', $client->readFixedSize(4, $deadline));

        $stop->cancel();
        Async\all($forwarders);
        $upstream->close();

        return [$client, $server, $relay];
    }
}
