<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Throwable;

/**
 * A server with SNI certificates serves the certificate of the host name each client asks for, and the main
 * certificate to every other client.
 *
 * The fixtures are self-signed: `server.crt` for `localhost`, `sni-a.crt` for `a.example.test` and `sni-b.crt` for
 * `b.example.test`, whose key also exists encrypted with the passphrase `secret`.
 */
final class SniCertificateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixture/certs/';

    /**
     * The passphrase of `sni-b.encrypted.key`.
     */
    private const string PASSPHRASE = 'secret';

    /**
     * How long a test may take before its token gives up, in seconds.
     */
    private const int DEADLINE_SECONDS = 5;

    /**
     * Each client receives the certificate of the name it asks for, or the main certificate for any other name.
     */
    public function testServesTheCertificateOfTheRequestedName(): void
    {
        $configuration = self::mainConfiguration()
            ->withSniCertificate('a.example.test', self::certificate('a'))
            ->withSniCertificate('b.example.test', self::certificate('b'));

        static::assertSame('a.example.test', self::servedSubject($configuration, 'a.example.test'));
        static::assertSame('b.example.test', self::servedSubject($configuration, 'b.example.test'));
        static::assertSame('localhost', self::servedSubject($configuration, 'other.example.test'));
    }

    /**
     * A `*.` key serves every name directly under it.
     */
    public function testServesAWildcardCertificate(): void
    {
        $configuration = self::mainConfiguration()
            ->withSniCertificates([
                '*.example.test' => self::certificate('a'),
            ]);

        static::assertSame('a.example.test', self::servedSubject($configuration, 'b.example.test'));
        static::assertSame('localhost', self::servedSubject($configuration, 'localhost'));
    }

    /**
     * An SNI certificate whose key needs a passphrase fails the handshake before it starts, naming the host.
     */
    public function testRefusesAnSniCertificateWhoseKeyNeedsAPassphrase(): void
    {
        $configuration = self::mainConfiguration()
            ->withSniCertificate('b.example.test', TLS\Certificate::create(
                self::FIXTURES . 'sni-b.crt',
                self::FIXTURES . 'sni-b.encrypted.key',
                self::PASSPHRASE,
            ));

        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $configuration, $deadline): void {
                    $plain = $listener->accept($deadline);

                    try {
                        new TLS\Acceptor($configuration)->accept($plain, $deadline);
                        static::fail('Expected the handshake to be refused.');
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertStringContainsString('"b.example.test"', $exception->getMessage());
                        static::assertStringContainsString('passphrase', $exception->getMessage());
                    } finally {
                        $plain->close();
                    }
                },
                'client' => static function () use ($listener, $deadline): void {
                    try {
                        self::connect($listener, 'b.example.test', $deadline)->close();
                        static::fail('Expected the handshake to fail.');
                    } catch (Throwable $exception) {
                        static::assertNotInstanceOf(Async\Exception\CancelledException::class, $exception);
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    /**
     * The documented way to serve a passphrase-protected key by name: inspect the name, then complete the handshake
     * with a configuration whose main certificate is that one.
     */
    public function testLazyAcceptorServesAPassphraseProtectedKeyByName(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            ['client' => $subject] = Async\concurrently([
                'client' => static function () use ($listener, $deadline): null|string {
                    $stream = self::connect($listener, 'b.example.test', $deadline);

                    try {
                        return $stream->getState()->peerCertificate?->subject;
                    } finally {
                        $stream->close();
                    }
                },
                'server' => static function () use ($listener, $deadline): void {
                    $hello = TLS\LazyAcceptor::default()->accept($listener->accept($deadline), $deadline);
                    static::assertSame('b.example.test', $hello->getServerName());

                    $configuration = TLS\ServerConfiguration::create(TLS\Certificate::create(
                        self::FIXTURES . 'sni-b.crt',
                        self::FIXTURES . 'sni-b.encrypted.key',
                        self::PASSPHRASE,
                    ));
                    $hello->complete($configuration, $deadline)->close();
                },
            ]);

            static::assertSame('b.example.test', $subject);
        } finally {
            $listener->close();
        }
    }

    /**
     * The subject of the certificate a client asking for the given name receives.
     */
    private static function servedSubject(TLS\ServerConfiguration $configuration, string $serverName): null|string
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = self::deadline();

        try {
            ['client' => $subject] = Async\concurrently([
                'client' => static function () use ($listener, $serverName, $deadline): null|string {
                    $stream = self::connect($listener, $serverName, $deadline);

                    try {
                        return $stream->getState()->peerCertificate?->subject;
                    } finally {
                        $stream->close();
                    }
                },
                'server' => static function () use ($listener, $configuration, $deadline): void {
                    new TLS\Acceptor($configuration)
                        ->accept($listener->accept($deadline), $deadline)
                        ->close();
                },
            ]);

            return $subject;
        } finally {
            $listener->close();
        }
    }

    /**
     * Connect to the listener and complete a handshake that asks for the given name, without verifying the peer.
     *
     * @param non-empty-string $serverName
     */
    private static function connect(
        Network\ListenerInterface $listener,
        string $serverName,
        Async\CancellationTokenInterface $deadline,
    ): TLS\StreamInterface {
        $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);

        return new TLS\Connector(TLS\ClientConfiguration::default()->withPeerVerification(false))->connect(
            $plain,
            $serverName,
            $deadline,
        );
    }

    /**
     * A configuration whose main certificate is the `localhost` fixture.
     */
    private static function mainConfiguration(): TLS\ServerConfiguration
    {
        return TLS\ServerConfiguration::create(TLS\Certificate::create(
            self::FIXTURES . 'server.crt',
            self::FIXTURES . 'server.key',
        ));
    }

    /**
     * The fixture certificate for `<name>.example.test`, with its unencrypted key.
     */
    private static function certificate(string $name): TLS\Certificate
    {
        return TLS\Certificate::create(self::FIXTURES . "sni-{$name}.crt", self::FIXTURES . "sni-{$name}.key");
    }

    /**
     * A token that ends a test that would otherwise hang.
     */
    private static function deadline(): Async\TimeoutCancellationToken
    {
        return new Async\TimeoutCancellationToken(Duration::seconds(self::DEADLINE_SECONDS));
    }
}
