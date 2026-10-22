<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;

use function intdiv;
use function str_repeat;
use function str_split;
use function strlen;
use function substr;

/**
 * The lazy acceptor waits for the whole ClientHello, however the client splits it, without consuming it and without
 * spinning on a socket that its first bytes keep readable.
 */
final class ClientHelloInspectionTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';

    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int DEADLINE_SECONDS = 5;

    /**
     * The fragment length of the records the relay splits the client's ClientHello into.
     */
    private const int FRAGMENT_LENGTH = 64;

    /**
     * How many writes the relay spreads those records over.
     */
    private const int BURSTS = 4;

    /**
     * The pause between two of those writes, in milliseconds.
     */
    private const int BURST_GAP_MILLISECONDS = 20;

    /**
     * How long the stalled peer is given before its token cancels the inspection, in milliseconds.
     */
    private const int STALL_MILLISECONDS = 700;

    /**
     * How many bytes of its ClientHello the stalled peer sends.
     */
    private const int STALLED_PREFIX_LENGTH = 10;

    /**
     * The most waits inspection may start while a peer stalls for {@see STALL_MILLISECONDS}: one for readability,
     * then pauses of 10, 20, 40, 80, 160 and 320 ms, with room for a slow machine.
     */
    private const int MAXIMUM_STALLED_WAITS = 10;

    /**
     * How many protocols the oversized ClientHello offers, and how long each name is: together, more than 16 KiB.
     */
    private const int LARGE_PROTOCOL_COUNT = 80;

    private const int LARGE_PROTOCOL_LENGTH = 250;

    /**
     * A ClientHello split into small records, and sent in several writes with pauses between them, is inspected
     * whole, and the handshake completes.
     */
    public function testInspectsAClientHelloSentInPiecesAcrossRecords(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $relay = new Relay($listener->getLocalAddress()->port, self::clientHelloReframer());
        $deadline = self::deadline();

        try {
            ['server' => $hello] = Async\concurrently([
                'relay' => static fn() => $relay->accept($deadline),
                'server' => static function () use ($listener, $deadline): TLS\ClientHello {
                    $hello = TLS\LazyAcceptor::default()->accept($listener->accept($deadline), $deadline);
                    $stream = $hello->complete(self::serverConfiguration(), $deadline);
                    $stream->writeAll('ok', $deadline);
                    $stream->close();

                    return $hello;
                },
                'client' => static function () use ($relay, $deadline): void {
                    $stream = TLS\connect(
                        '127.0.0.1',
                        $relay->port(),
                        TLS\ClientConfiguration::default()
                            ->withPeerVerification(false)
                            ->withPeerName('localhost')
                            ->withAlpnProtocols(['h2', 'http/1.1']),
                        $deadline,
                    );
                    static::assertSame('ok', $stream->readFixedSize(2, $deadline));
                    $stream->close();
                },
            ]);
        } finally {
            $relay->close();
            $listener->close();
        }

        static::assertSame('localhost', $hello->getServerName());
        static::assertSame(['h2', 'http/1.1'], $hello->getAlpnProtocols());
    }

    /**
     * A peer that sends part of its ClientHello and stops is waited for with pauses that grow, not by spinning, until
     * the token ends the inspection; nothing it sent is consumed.
     */
    public function testWaitsForAStalledPeerWithoutSpinningOrConsuming(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();
        $record = WireFormat::frameHandshake(WireFormat::clientHello([WireFormat::serverName(
            'stalled.example.test',
        )]), WireFormat::MAXIMUM_FRAGMENT_LENGTH);

        try {
            $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
            $server = $listener->accept($deadline);
            $client->writeAll(substr($record, 0, self::STALLED_PREFIX_LENGTH), $deadline);

            $token =
                new CountingCancellationToken(new Async\TimeoutCancellationToken(Duration::milliseconds(self::STALL_MILLISECONDS)));
            $lazy = TLS\LazyAcceptor::default();

            $failure = null;
            try {
                $lazy->accept($server, $token);
            } catch (Async\Exception\CancelledException $e) {
                $failure = $e;
            }

            static::assertNotNull($failure, 'The inspection must wait for the rest of the ClientHello.');
            static::assertGreaterThan(2, $token->subscriptions, 'The inspection must peek again after pausing.');
            static::assertLessThanOrEqual(self::MAXIMUM_STALLED_WAITS, $token->subscriptions);

            $client->writeAll(substr($record, self::STALLED_PREFIX_LENGTH), $deadline);
            static::assertSame('stalled.example.test', $lazy->accept($server, $deadline)->getServerName());

            $server->close();
            $client->close();
        } finally {
            $listener->close();
        }
    }

    /**
     * A ClientHello longer than the 16 KiB inspection limit is reported from the extensions that end within it, and
     * its handshake completes.
     */
    public function testInspectsTheStartOfAnOversizedClientHello(): void
    {
        $protocols = [];
        for ($i = 0; $i < self::LARGE_PROTOCOL_COUNT; $i++) {
            $protocols[] = str_repeat('p', self::LARGE_PROTOCOL_LENGTH);
        }

        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            ['server' => $serverName] = Async\concurrently([
                'server' => static function () use ($listener, $deadline): null|string {
                    $hello = TLS\LazyAcceptor::default()->accept($listener->accept($deadline), $deadline);
                    $stream = $hello->complete(self::serverConfiguration(), $deadline);
                    $stream->writeAll('ok', $deadline);
                    $stream->close();

                    return $hello->getServerName();
                },
                'client' => static function () use ($listener, $protocols, $deadline): void {
                    $stream = TLS\connect(
                        '127.0.0.1',
                        $listener->getLocalAddress()->port,
                        TLS\ClientConfiguration::default()
                            ->withPeerVerification(false)
                            ->withPeerName('localhost')
                            ->withAlpnProtocols($protocols),
                        $deadline,
                    );
                    static::assertSame('ok', $stream->readFixedSize(2, $deadline));
                    $stream->close();
                },
            ]);
        } finally {
            $listener->close();
        }

        static::assertSame('localhost', $serverName);
    }

    /**
     * A forwarder that splits the client's first record, its ClientHello, into small records, writes them in several
     * writes with pauses between them, and forwards everything else unchanged.
     *
     * @return (Closure(string, Network\StreamInterface, Async\CancellationTokenInterface): void)
     */
    private static function clientHelloReframer(): Closure
    {
        $pending = '';
        $done = false;

        return static function (
            string $chunk,
            Network\StreamInterface $to,
            Async\CancellationTokenInterface $cancellation,
        ) use (&$pending, &$done): void {
            if ($done) {
                $to->writeAll($chunk, $cancellation);

                return;
            }

            $pending .= $chunk;
            $records = WireFormat::records($pending);
            if ([] === $records) {
                return;
            }

            $done = true;
            [, $fragment] = $records[0];
            $reframed = WireFormat::frameHandshake($fragment, self::FRAGMENT_LENGTH);
            foreach (str_split($reframed, intdiv(strlen($reframed), self::BURSTS) + 1) as $piece) {
                $to->writeAll($piece, $cancellation);
                Async\sleep(Duration::milliseconds(self::BURST_GAP_MILLISECONDS), $cancellation);
            }

            $rest = substr($pending, WireFormat::RECORD_HEADER_LENGTH + strlen($fragment));
            if ('' !== $rest) {
                $to->writeAll($rest, $cancellation);
            }
        };
    }

    /**
     * A server configuration with the fixture certificate.
     */
    private static function serverConfiguration(): TLS\ServerConfiguration
    {
        return TLS\ServerConfiguration::create(TLS\Certificate::create(self::CERT_FILE, self::KEY_FILE));
    }

    /**
     * A token that ends a test that would otherwise hang.
     */
    private static function deadline(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(self::DEADLINE_SECONDS));
    }
}
