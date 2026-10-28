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

use function str_repeat;

final class PeerVerificationTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';
    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * @return iterable<string, array{TLS\ClientConfiguration, string}>
     */
    public static function rejectedConfigurations(): iterable
    {
        yield 'untrusted chain' => [
            new TLS\ClientConfiguration(peerName: 'localhost', peerNameVerification: false),
            'certificate verify failed',
        ];

        yield 'peer name mismatch' => [
            new TLS\ClientConfiguration(peerName: 'wrong.example', certificateAuthority: self::CERT_FILE),
            'did not match expected CN',
        ];

        yield 'fingerprint mismatch' => [
            new TLS\ClientConfiguration(
                peerName: 'localhost',
                certificateAuthority: self::CERT_FILE,
                peerFingerprints: [str_repeat('0', 64)],
            ),
            'fingerprint',
        ];
    }

    #[DataProvider('rejectedConfigurations')]
    public function testRejectedCertificateIsNonRetryable(TLS\ClientConfiguration $configuration, string $reason): void
    {
        $listener = new TLS\Listener(
            TCP\listen('127.0.0.1', 0),
            TLS\ServerConfiguration::create(new TLS\Certificate(self::CERT_FILE, self::KEY_FILE)),
        );
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $deadline): void {
                    try {
                        $listener->accept($deadline)->close();
                    } catch (TLS\Exception\HandshakeFailedException) {
                        // A client that rejects the chain aborts the handshake with an alert;
                        // one that rejects the name or the pin completes it and then closes.
                        return;
                    }
                },
                'client' => static function () use ($listener, $configuration, $reason, $deadline): void {
                    try {
                        TLS\connect('127.0.0.1', $listener->getLocalAddress()->port, $configuration, $deadline);
                        static::fail('Expected the peer certificate to be rejected.');
                    } catch (TLS\Exception\PeerVerificationException $exception) {
                        static::assertInstanceOf(Network\Exception\NonRetryableExceptionInterface::class, $exception);
                        static::assertStringContainsString($reason, $exception->getMessage());
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    public function testOtherHandshakeFailuresStayRetryable(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $deadline): void {
                    // A plain-text peer answers the ClientHello with HTTP.
                    $stream = $listener->accept($deadline);
                    $stream->writeAll("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n", $deadline);
                    $stream->close();
                },
                'client' => static function () use ($listener, $deadline): void {
                    try {
                        TLS\connect(
                            '127.0.0.1',
                            $listener->getLocalAddress()->port,
                            TLS\ClientConfiguration::default()->withPeerVerification(false),
                            $deadline,
                        );
                        static::fail('Expected the handshake to fail.');
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertNotInstanceOf(TLS\Exception\PeerVerificationException::class, $exception);
                        static::assertNotInstanceOf(
                            Network\Exception\NonRetryableExceptionInterface::class,
                            $exception,
                        );
                        static::assertStringStartsWith('TLS handshake failed: ', $exception->getMessage());
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function nativeMessages(): iterable
    {
        yield 'OpenSSL 3 certificate verify failed' => [
            "SSL operation failed with code 1. OpenSSL Error messages:\nerror:0A000086:SSL routines::certificate verify failed",
            true,
        ];
        yield 'OpenSSL 1.1.1 certificate verify failed' => [
            "SSL operation failed with code 1. OpenSSL Error messages:\nerror:1416F086:SSL routines:tls_process_server_certificate:certificate verify failed",
            true,
        ];
        yield 'OpenSSL 3 wrong version number' => [
            "SSL operation failed with code 1. OpenSSL Error messages:\nerror:0A00010B:SSL routines::wrong version number",
            false,
        ];
        yield 'OpenSSL 3 unexpected eof' => [
            "SSL: Unexpected EOF\nerror:0A000126:SSL routines::unexpected eof while reading",
            false,
        ];
        yield 'peer name' => ["Peer certificate CN=`localhost' did not match expected CN=`example.com'", true];
        yield 'policy' => ['Could not verify peer: code:18 self-signed certificate', true];
        yield 'missing peer certificate' => ['Could not get peer certificate', true];
        yield 'fingerprint' => ['peer_fingerprint match failure', true];
        yield 'connection reset' => ['SSL: Connection reset by peer', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('nativeMessages')]
    public function testVerificationFailuresAreRecognisedByCodeOrFixedMessage(string $native, bool $verification): void
    {
        static::assertSame($verification, TLS\Internal\is_verification_failure($native));

        $exception = TLS\Internal\handshake_failure($native);
        static::assertSame($verification, $exception instanceof TLS\Exception\PeerVerificationException);
        static::assertSame(
            $native === '' ? 'TLS handshake failed.' : 'TLS handshake failed: ' . $native,
            $exception->getMessage(),
        );
    }
}
