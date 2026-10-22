<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;
use Psl\TLS;

use function extension_loaded;
use function socket_import_stream;
use function socket_set_option;
use function str_repeat;

use const SO_RCVBUF;
use const SO_SNDBUF;
use const SOL_SOCKET;

/**
 * A handshake goes on when it waits to write rather than to read, and waits for a silent peer without spinning.
 */
final class HandshakeWaitTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';

    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int DEADLINE_SECONDS = 5;

    /**
     * The socket buffer size both ends ask for: far smaller than the ClientHello.
     */
    private const int SMALL_BUFFER = 4_096;

    /**
     * How many protocols the client offers, and how long each name is: a ClientHello of about 15 KiB.
     */
    private const int PROTOCOL_COUNT = 60;

    private const int PROTOCOL_LENGTH = 250;

    /**
     * How long the silent peer is given before the token cancels the handshake, in milliseconds.
     */
    private const int SILENCE_MILLISECONDS = 700;

    /**
     * The most waits a handshake may start while its peer stays silent for {@see SILENCE_MILLISECONDS}: one per
     * pause of 10, 20, 40, 80, 160 and 320 ms, with room for a slow machine.
     */
    private const int MAXIMUM_SILENT_WAITS = 10;

    /**
     * A client whose ClientHello does not fit the socket buffers completes its handshake: OpenSSL waits to write the
     * rest of it, while the server waits to read it and sends nothing.
     */
    public function testHandshakeCompletesWhenItsFirstFlightDoesNotFitTheSocketBuffers(): void
    {
        if (!extension_loaded('sockets')) {
            static::markTestSkipped('Shrinking the socket buffers needs ext-sockets.');
        }

        $protocols = [];
        for ($i = 0; $i < self::PROTOCOL_COUNT; $i++) {
            $protocols[] = str_repeat('p', self::PROTOCOL_LENGTH);
        }

        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            ['client' => $received] = Async\concurrently([
                'server' => static function () use ($listener, $deadline): void {
                    $plain = $listener->accept($deadline);
                    self::shrinkBuffers($plain->getStream());
                    $stream = new TLS\Acceptor(TLS\ServerConfiguration::create(TLS\Certificate::create(
                        self::CERT_FILE,
                        self::KEY_FILE,
                    )))->accept($plain, $deadline);
                    $stream->writeAll('ok', $deadline);
                    $stream->close();
                },
                'client' => static function () use ($listener, $protocols, $deadline): string {
                    $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
                    self::shrinkBuffers($plain->getStream());
                    $connector = new TLS\Connector(
                        TLS\ClientConfiguration::default()->withPeerVerification(false)->withAlpnProtocols($protocols),
                    );
                    $stream = $connector->connect($plain, 'localhost', $deadline);

                    try {
                        return $stream->readFixedSize(2, $deadline);
                    } finally {
                        $stream->close();
                    }
                },
            ]);
        } finally {
            $listener->close();
        }

        static::assertSame('ok', $received);
    }

    /**
     * A handshake whose peer never answers waits with pauses that grow, not by spinning, until the token ends it.
     */
    public function testHandshakeWaitsForASilentPeerWithoutSpinning(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
            $server = $listener->accept($deadline);
            $token =
                new CountingCancellationToken(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SILENCE_MILLISECONDS)));

            $failure = null;
            try {
                new TLS\Connector(TLS\ClientConfiguration::default()->withPeerVerification(false))->connect(
                    $client,
                    'localhost',
                    $token,
                );
            } catch (Async\Exception\CancelledException $e) {
                $failure = $e;
            }

            static::assertNotNull($failure, 'The handshake must wait for the silent peer.');
            static::assertGreaterThan(1, $token->subscriptions, 'The handshake must take another step after a pause.');
            static::assertLessThanOrEqual(self::MAXIMUM_SILENT_WAITS, $token->subscriptions);

            $client->close();
            $server->close();
        } finally {
            $listener->close();
        }
    }

    /**
     * Ask for small send and receive buffers on the socket of a stream.
     *
     * @param mixed $resource The stream's resource.
     */
    private static function shrinkBuffers(mixed $resource): void
    {
        static::assertIsResource($resource);
        $socket = socket_import_stream($resource);
        static::assertNotFalse($socket);
        static::assertNotNull($socket);
        socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, self::SMALL_BUFFER);
        socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, self::SMALL_BUFFER);
    }

    /**
     * A token that ends a test that would otherwise hang.
     */
    private static function deadline(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(self::DEADLINE_SECONDS));
    }
}
