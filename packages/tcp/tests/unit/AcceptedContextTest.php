<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;

use function function_exists;
use function socket_get_option;
use function socket_import_stream;
use function stream_context_get_options;
use function stream_context_set_option;

use const SOL_TCP;
use const TCP_NODELAY;

/**
 * Streams accepted with `stream_socket_accept()` share the listening socket's stream context,
 * so TLS options and captured peer certificates written for one connection are visible on
 * every other connection of the same listener. With ext-sockets, the listener accepts through
 * `socket_accept()` and `socket_export_stream()`, which gives each connection its own context.
 */
final class AcceptedContextTest extends TestCase
{
    /**
     * @return list<array{bool}>
     */
    public static function noDelayOptions(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('noDelayOptions')]
    public function testAcceptedStreamsDoNotShareTheListenerContext(bool $noDelay): void
    {
        if (!function_exists('socket_import_stream')) {
            static::markTestSkipped('Per-connection stream contexts require ext-sockets.');
        }

        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: $noDelay));
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));
        $port = $listener->getLocalAddress()->port;

        $clients = [];
        $servers = [];
        try {
            foreach ([0, 1] as $index) {
                $clients[$index] = TCP\connect('127.0.0.1', $port, cancellation: $deadline);
                $servers[$index] = $listener->accept($deadline);
            }

            $first = $servers[0]->getStream();
            $second = $servers[1]->getStream();
            static::assertIsResource($first);
            static::assertIsResource($second);

            foreach ([$first, $second] as $resource) {
                static::assertArrayNotHasKey('ssl', stream_context_get_options($resource));

                $socket = socket_import_stream($resource);
                static::assertNotFalse($socket);
                // Platforms report a set TCP_NODELAY with different non-zero values (macOS returns the flag bit).
                static::assertSame($noDelay, socket_get_option($socket, SOL_TCP, TCP_NODELAY) !== 0);
            }

            // An option written for one connection must stay invisible to the other connection.
            stream_context_set_option($first, 'ssl', 'verify_peer', true);
            stream_context_set_option($first, 'ssl', 'peer_certificate', 'first');

            static::assertArrayNotHasKey('ssl', stream_context_get_options($second));
            static::assertSame(
                ['verify_peer' => true, 'peer_certificate' => 'first'],
                stream_context_get_options($first)['ssl'],
            );
        } finally {
            foreach ($servers as $server) {
                $server->close();
            }

            foreach ($clients as $client) {
                $client->close();
            }

            $listener->close();
        }
    }

    public function testAcceptedStreamsExchangeData(): void
    {
        $listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(noDelay: true));
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
            $server = $listener->accept($deadline);

            try {
                static::assertSame('127.0.0.1', $server->getPeerAddress()->host);
                static::assertSame($client->getLocalAddress()->port, $server->getPeerAddress()->port);

                $client->writeAll('ping', $deadline);
                static::assertSame('ping', $server->readFixedSize(4, $deadline));

                $server->writeAll('pong', $deadline);
                static::assertSame('pong', $client->readFixedSize(4, $deadline));
            } finally {
                $server->close();
                $client->close();
            }
        } finally {
            $listener->close();
        }
    }
}
