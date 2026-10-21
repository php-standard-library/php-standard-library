<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;
use Throwable;

use function function_exists;
use function stream_context_get_options;
use function stream_context_set_option;

/**
 * Server handshakes must not leak state between connections of one listener.
 *
 * PHP captures the peer certificate into the stream context, and streams accepted with
 * `stream_socket_accept()` share the listening socket's context. The fixture certificate
 * is a self-signed CA, so it serves both as the server certificate, as the client
 * certificate, and as the trust anchor that verifies it.
 */
final class ServerContextIsolationTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';
    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * @return list<array{string}>
     */
    public static function upgradePaths(): array
    {
        return [['acceptor'], ['lazy'], ['listener']];
    }

    #[DataProvider('upgradePaths')]
    public function testClientWithoutCertificateDoesNotInheritPreviousIdentity(string $path): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $certificate = new TLS\Certificate(self::CERT_FILE, self::KEY_FILE);
        $configuration = new TLS\ServerConfiguration($certificate, certificateAuthority: self::CERT_FILE);
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));
        $tlsListener = new TLS\Listener($listener, $configuration);

        try {
            foreach ([null, $certificate, null] as $clientCertificate) {
                Async\concurrently([
                    'server' => static function () use (
                        $listener,
                        $tlsListener,
                        $configuration,
                        $clientCertificate,
                        $path,
                        $deadline,
                    ): void {
                        $server = match ($path) {
                            'listener' => $tlsListener->accept($deadline),
                            'lazy' => TLS\LazyAcceptor::default()
                                ->accept($listener->accept($deadline), $deadline)
                                ->complete($configuration, $deadline),
                            default => new TLS\Acceptor($configuration)->accept(
                                $listener->accept($deadline),
                                $deadline,
                            ),
                        };

                        self::assertPeerAndReply($server, $clientCertificate !== null, $deadline);
                    },
                    'client' => static fn(): string => self::connectAndRead($listener, $clientCertificate, $deadline),
                ]);
            }
        } finally {
            $listener->close();
        }
    }

    public function testServerConfigurationDoesNotInheritTrustSettings(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $certificate = new TLS\Certificate(self::CERT_FILE, self::KEY_FILE);
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            foreach ([self::CERT_FILE, null] as $authority) {
                Async\concurrently([
                    'server' => static function () use ($listener, $certificate, $authority, $deadline): void {
                        $plain = $listener->accept($deadline);
                        $acceptor = new TLS\Acceptor(
                            new TLS\ServerConfiguration($certificate, certificateAuthority: $authority),
                        );

                        try {
                            $server = $acceptor->accept($plain, $deadline);
                        } catch (TLS\Exception\HandshakeFailedException|Network\Exception\RuntimeException $exception) {
                            $plain->close();
                            static::assertNull(
                                $authority,
                                'The configured authority must accept the client certificate.',
                            );
                            self::assertRejectedWithoutInheritedAuthority($exception);

                            return;
                        }

                        static::assertNotNull($authority, 'The previous authority must not verify this client.');
                        self::assertPeerAndReply($server, true, $deadline);
                    },
                    'client' => static function () use ($listener, $certificate, $authority, $deadline): void {
                        try {
                            $response = self::connectAndRead($listener, $certificate, $deadline);
                        } catch (Throwable) {
                            static::assertNull($authority);

                            return;
                        }

                        static::assertSame($authority === null ? '' : 'ok', $response);
                    },
                ]);
            }
        } finally {
            $listener->close();
        }
    }

    public function testOverlappingHandshakesReportEachPeerCorrectly(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $certificate = new TLS\Certificate(self::CERT_FILE, self::KEY_FILE);
        $acceptor = new TLS\Acceptor(new TLS\ServerConfiguration($certificate, certificateAuthority: self::CERT_FILE));
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(10));

        $server = static function () use ($listener, $acceptor, $deadline): void {
            $stream = $acceptor->accept($listener->accept($deadline), $deadline);

            try {
                // The client announces whether it presented a certificate.
                $expected = $stream->readFixedSize(4, $deadline);
                $peer = $stream->getState()->peerCertificate;
                if ($expected === 'cert') {
                    static::assertSame('localhost', $peer?->subject);
                } else {
                    static::assertSame('none', $expected);
                    static::assertNull($peer);
                }

                $stream->writeAll('ok', $deadline);
            } finally {
                $stream->close();
            }
        };

        $client = static function (null|TLS\Certificate $clientCertificate) use ($listener, $deadline): void {
            $stream = TLS\connect(
                '127.0.0.1',
                $listener->getLocalAddress()->port,
                new TLS\ClientConfiguration(
                    peerName: 'localhost',
                    certificateAuthority: self::CERT_FILE,
                    certificate: $clientCertificate,
                ),
                $deadline,
            );

            try {
                $stream->writeAll($clientCertificate === null ? 'none' : 'cert', $deadline);
                static::assertSame('ok', $stream->readAll(cancellation: $deadline));
            } finally {
                $stream->close();
            }
        };

        try {
            for ($round = 0; $round < 3; $round++) {
                Async\concurrently([
                    $server,
                    $server,
                    $server,
                    static fn() => $client($certificate),
                    static fn() => $client(null),
                    static fn() => $client($certificate),
                ]);
            }
        } finally {
            $listener->close();
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function trustOptions(): array
    {
        return [['cafile'], ['capath']];
    }

    #[DataProvider('trustOptions')]
    public function testRefusesStreamWhoseContextCarriesForeignTrustSettings(string $option): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));
        $client = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);

        try {
            $plain = $listener->accept($deadline);
            $resource = $plain->getStream();
            static::assertIsResource($resource);

            // Simulate a trust setting left behind on a shared context by another configuration.
            stream_context_set_option($resource, 'ssl', $option, self::CERT_FILE);

            $acceptor = new TLS\Acceptor(TLS\ServerConfiguration::create(new TLS\Certificate(
                self::CERT_FILE,
                self::KEY_FILE,
            )));

            try {
                $acceptor->accept($plain, $deadline);
                static::fail('Expected the stream to be refused.');
            } catch (Network\Exception\RuntimeException $exception) {
                static::assertStringContainsString("\"{$option}\"", $exception->getMessage());
            } finally {
                $plain->close();
            }
        } finally {
            $client->close();
            $listener->close();
        }
    }

    public function testCapturedPeerCertificateIsRemovedFromTheServerStreamContext(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $certificate = new TLS\Certificate(self::CERT_FILE, self::KEY_FILE);
        $acceptor = new TLS\Acceptor(new TLS\ServerConfiguration($certificate, certificateAuthority: self::CERT_FILE));
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $acceptor, $deadline): void {
                    $server = $acceptor->accept($listener->accept($deadline), $deadline);

                    try {
                        static::assertSame('localhost', $server->getState()->peerCertificate?->subject);

                        $resource = $server->getStream();
                        static::assertIsResource($resource);
                        $options = stream_context_get_options($resource)['ssl'];
                        static::assertArrayHasKey('peer_certificate', $options);
                        static::assertNull($options['peer_certificate']);
                        static::assertNull($options['peer_certificate_chain']);

                        $server->writeAll('ok', $deadline);
                    } finally {
                        $server->close();
                    }
                },
                'client' => static fn(): string => self::connectAndRead($listener, $certificate, $deadline),
            ]);
        } finally {
            $listener->close();
        }
    }

    private static function assertPeerAndReply(
        TLS\StreamInterface $server,
        bool $expectPeerCertificate,
        Async\CancellationTokenInterface $deadline,
    ): void {
        try {
            $state = $server->getState();
            if ($expectPeerCertificate) {
                // A self-signed client certificate comes with an empty chain on the server side.
                static::assertSame('localhost', $state->peerCertificate?->subject);
            } else {
                static::assertNull($state->peerCertificate);
                static::assertNull($state->peerCertificateChain);
            }

            $server->writeAll('ok', $deadline);
        } finally {
            $server->close();
        }
    }

    private static function assertRejectedWithoutInheritedAuthority(Throwable $exception): void
    {
        if (function_exists('socket_import_stream')) {
            // Private context: the configuration without an authority cannot verify the client certificate.
            static::assertInstanceOf(TLS\Exception\HandshakeFailedException::class, $exception);

            return;
        }

        // Shared context: the inherited authority is detected and the stream is refused.
        static::assertInstanceOf(Network\Exception\RuntimeException::class, $exception);
        static::assertStringContainsString('"cafile"', $exception->getMessage());
    }

    private static function connectAndRead(
        Network\ListenerInterface $listener,
        null|TLS\Certificate $clientCertificate,
        Async\CancellationTokenInterface $deadline,
    ): string {
        $client = TLS\connect(
            '127.0.0.1',
            $listener->getLocalAddress()->port,
            new TLS\ClientConfiguration(
                peerName: 'localhost',
                certificateAuthority: self::CERT_FILE,
                certificate: $clientCertificate,
            ),
            $deadline,
        );

        try {
            return $client->readAll(cancellation: $deadline);
        } finally {
            $client->close();
        }
    }
}
