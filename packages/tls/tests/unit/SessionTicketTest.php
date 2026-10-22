<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use OpenSSL\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;
use Psl\TLS;

use function array_column;
use function in_array;
use function stream_context_set_option;

use const PHP_VERSION_ID;

/**
 * `sessionTickets: false` turns session tickets off: a client offers none, and a server issues none.
 *
 * TLS 1.2 tickets are visible on the wire: the session ticket extension of the ClientHello and the ServerHello, and the
 * NewSessionTicket message the server sends before its ChangeCipherSpec. TLS 1.3 tickets are encrypted, so the test
 * counts the sessions the client's OpenSSL reports instead.
 */
final class SessionTicketTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';

    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int DEADLINE_SECONDS = 5;

    /**
     * The first PHP version whose OpenSSL binding reads `num_tickets` and reports client sessions.
     */
    private const int PHP_86 = 80_600;

    /**
     * How many TLS 1.3 tickets OpenSSL sends after a handshake by default (SSL_CTX_set_num_tickets(3)).
     */
    private const int OPENSSL_DEFAULT_TICKETS = 2;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function ticketSettings(): iterable
    {
        yield 'enabled' => [true];
        yield 'disabled' => [false];
    }

    /**
     * A client offers the TLS 1.2 session ticket extension only while tickets are enabled.
     */
    #[DataProvider('ticketSettings')]
    public function testClientOffersATicketOnlyWhenEnabled(bool $enabled): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            ['server' => $hello] = Async\concurrently([
                'server' => static function () use ($listener, $deadline): string {
                    $plain = $listener->accept($deadline);
                    $record = WireFormat::readRecord($plain, $deadline);
                    $plain->close();

                    return $record;
                },
                'client' => static function () use ($listener, $enabled, $deadline): void {
                    $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
                    $connector = new TLS\Connector(
                        TLS\ClientConfiguration::default()->withPeerVerification(false)->withSessionTickets($enabled),
                    );

                    try {
                        $connector->connect($plain, 'localhost', $deadline);
                        static::fail('The plain server cannot complete the handshake.');
                    } catch (TLS\Exception\HandshakeFailedException) {
                        $plain->close();
                    }
                },
            ]);
        } finally {
            $listener->close();
        }

        [[$type, $body]] = WireFormat::handshakeMessages(WireFormat::records($hello));
        static::assertSame(WireFormat::CLIENT_HELLO, $type);
        static::assertSame($enabled, in_array(
            WireFormat::SESSION_TICKET_EXTENSION,
            WireFormat::helloExtensionTypes($body, true),
            true,
        ));
    }

    /**
     * A TLS 1.2 server acknowledges the ticket extension and sends a NewSessionTicket only while tickets are enabled.
     */
    #[DataProvider('ticketSettings')]
    public function testTls12ServerIssuesATicketOnlyWhenEnabled(bool $enabled): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $relay = new Relay($listener->getLocalAddress()->port);
        $deadline = self::deadline();
        $configuration = self::serverConfiguration()
            ->withMaximumVersion(TLS\Version::Tls12)
            ->withSessionTickets($enabled);

        try {
            Async\concurrently([
                'relay' => static fn() => $relay->accept($deadline),
                'server' => static function () use ($listener, $configuration, $deadline): void {
                    $stream = new TLS\Acceptor($configuration)->accept($listener->accept($deadline), $deadline);
                    $stream->writeAll('ok', $deadline);
                    $stream->close();
                },
                'client' => static function () use ($relay, $deadline): void {
                    $stream = TLS\connect(
                        '127.0.0.1',
                        $relay->port(),
                        TLS\ClientConfiguration::default()->withPeerVerification(false),
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

        $messages = WireFormat::handshakeMessages(WireFormat::records($relay->fromServer()));
        static::assertSame(WireFormat::SERVER_HELLO, $messages[0][0]);
        static::assertSame($enabled, in_array(
            WireFormat::SESSION_TICKET_EXTENSION,
            WireFormat::helloExtensionTypes($messages[0][1], false),
            true,
        ));
        static::assertSame($enabled, in_array(WireFormat::NEW_SESSION_TICKET, array_column($messages, 0), true));
    }

    /**
     * A TLS 1.3 server sends OpenSSL's default number of tickets while tickets are enabled, and none while disabled.
     */
    #[DataProvider('ticketSettings')]
    public function testTls13ServerIssuesTicketsOnlyWhenEnabled(bool $enabled): void
    {
        if (PHP_VERSION_ID < self::PHP_86) {
            static::markTestSkipped('PHP 8.4 and 8.5 cannot turn TLS 1.3 tickets off, nor report them to a client.');
        }

        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();
        $configuration = self::serverConfiguration()
            ->withMinimumVersion(TLS\Version::Tls13)
            ->withSessionTickets($enabled);
        $sessions = 0;

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $configuration, $deadline): void {
                    $stream = new TLS\Acceptor($configuration)->accept($listener->accept($deadline), $deadline);
                    $stream->writeAll('ok', $deadline);
                    $stream->close();
                },
                'client' => static function () use ($listener, $deadline, &$sessions): void {
                    $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
                    $resource = $plain->getStream();
                    static::assertIsResource($resource);
                    // OpenSSL reports every ticket it receives as a session; the connector keeps this option.
                    stream_context_set_option($resource, 'ssl', 'session_new_cb', static function (
                        mixed $_,
                        Session $session,
                    ) use (&$sessions): void {
                        static::assertTrue($session->hasTicket());
                        $sessions++;
                    });

                    $stream = new TLS\Connector(TLS\ClientConfiguration::default()->withPeerVerification(
                        false,
                    ))->connect($plain, 'localhost', $deadline);
                    // The tickets arrive before the application data, and are processed by the read.
                    static::assertSame('ok', $stream->readFixedSize(2, $deadline));
                    $stream->close();
                },
            ]);
        } finally {
            $listener->close();
        }

        static::assertSame($enabled ? self::OPENSSL_DEFAULT_TICKETS : 0, $sessions);
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
