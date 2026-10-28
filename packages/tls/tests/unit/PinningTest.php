<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\TCP;
use Psl\TLS;

use function file_get_contents;
use function implode;
use function openssl_x509_fingerprint;
use function openssl_x509_read;
use function str_repeat;
use function str_split;
use function strtoupper;

use const PHP_OS_FAMILY;

final class PinningTest extends TestCase
{
    private const string CERT_FILE = __DIR__ . '/../../fixture/certs/server.crt';
    private const string KEY_FILE = __DIR__ . '/../../fixture/certs/server.key';

    /**
     * @return iterable<string, array{list<non-empty-string>, bool}>
     */
    public static function pins(): iterable
    {
        $pin = self::fixtureFingerprint();
        $wrong = str_repeat('0', 64);

        yield 'matching pin' => [[$pin], true];
        yield 'wrong pin' => [[$wrong], false];
        yield 'later pin matches' => [[$wrong, $pin], true];
        yield 'upper case with colons' => [[strtoupper(implode(':', str_split($pin, 2)))], true];
    }

    /**
     * @param list<non-empty-string> $pins
     */
    #[DataProvider('pins')]
    public function testPeerFingerprintIsCheckedAgainstEveryPin(array $pins, bool $accepted): void
    {
        if (!$accepted && PHP_OS_FAMILY === 'Windows') {
            // php_openssl_enable_crypto() tells a step that waits from one that failed by errno, which Winsock
            // never resets, so a handshake whose peer closes before the server has finished never ends.
            static::markTestSkipped(
                'On Windows, PHP reports a handshake step that failed on the socket as in progress.',
            );
        }

        $listener = new TLS\Listener(
            TCP\listen('127.0.0.1', 0),
            TLS\ServerConfiguration::create(new TLS\Certificate(self::CERT_FILE, self::KEY_FILE)),
        );
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            Async\concurrently([
                'server' => static function () use ($listener, $deadline, $accepted): void {
                    $server = $listener->accept($deadline);

                    try {
                        // A rejected client closes without reading; a pinned one reads the reply.
                        $server->writeAll('ok', $deadline);
                    } catch (IO\Exception\RuntimeException $exception) {
                        // The rejected client may close before or while the reply is written.
                        if ($accepted) {
                            throw $exception;
                        }
                    } finally {
                        $server->close();
                    }
                },
                'client' => static function () use ($listener, $pins, $accepted, $deadline): void {
                    $plain = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port, cancellation: $deadline);
                    $connector = new TLS\Connector(new TLS\ClientConfiguration(
                        peerName: 'localhost',
                        certificateAuthority: self::CERT_FILE,
                        peerFingerprints: $pins,
                    ));

                    try {
                        $client = $connector->connect($plain, 'localhost', $deadline);
                    } catch (TLS\Exception\HandshakeFailedException $exception) {
                        static::assertFalse($accepted, 'The matching pin must be accepted.');
                        static::assertStringContainsString('fingerprint', $exception->getMessage());
                        static::assertTrue($plain->isClosed(), 'A rejected connection must be closed.');

                        return;
                    }

                    try {
                        static::assertTrue($accepted, 'A wrong pin must be rejected.');
                        static::assertSame(
                            self::fixtureFingerprint(),
                            $client->getState()->peerCertificate?->fingerprint,
                        );
                        static::assertSame('ok', $client->readAll(cancellation: $deadline));
                    } finally {
                        $client->close();
                    }
                },
            ]);
        } finally {
            $listener->close();
        }
    }

    /**
     * @return non-empty-string
     */
    private static function fixtureFingerprint(): string
    {
        $certificate = openssl_x509_read((string) file_get_contents(self::CERT_FILE));
        static::assertNotFalse($certificate);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        static::assertNotFalse($fingerprint);
        static::assertNotSame('', $fingerprint);

        return $fingerprint;
    }
}
