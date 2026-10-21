<?php

declare(strict_types=1);

namespace Psl\TLS;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Default\DefaultInterface;
use Psl\Network;
use Psl\TLS\Exception\HandshakeFailedException;

use function hash_equals;
use function is_resource;
use function str_replace;
use function stream_context_set_options;
use function stream_socket_enable_crypto;
use function strtolower;

/**
 * Performs TLS client handshakes on existing streams.
 *
 * Takes a plain stream and upgrades it to a TLS-encrypted stream
 * using the provided {@see ClientConfiguration}.
 *
 * Usage:
 *   $connector = Connector::default();
 *   $tls = $connector->connect(TCP\connect('example.com', 443), 'example.com');
 *
 * @api
 */
final readonly class Connector implements DefaultInterface
{
    public function __construct(
        private ClientConfiguration $clientConfiguration = new ClientConfiguration(),
    ) {}

    /**
     * @pure
     */
    #[Override]
    public static function default(): static
    {
        return new self();
    }

    /**
     * Perform a TLS handshake on the given stream.
     *
     * @param non-empty-string|null $serverName SNI hostname override. If null, uses the config's peerName.
     *
     * When the configuration pins peer fingerprints, the peer certificate's SHA-256 fingerprint
     * is compared with them after the handshake; on a mismatch the stream is closed and
     * {@see HandshakeFailedException} is thrown.
     *
     * @throws HandshakeFailedException If the TLS handshake fails, or the peer certificate matches no configured fingerprint.
     * @throws Network\Exception\RuntimeException If the stream is not available.
     * @throws CancelledException If the cancellation token is cancelled during the handshake.
     */
    public function connect(
        Network\StreamInterface $stream,
        null|string $serverName = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        $resource = $stream->getStream();
        if (!is_resource($resource)) {
            throw new Network\Exception\RuntimeException('Stream resource is not available.');
        }

        $clientConfiguration = $this->clientConfiguration;
        if ($serverName !== null && $clientConfiguration->peerName === null) {
            $clientConfiguration = $clientConfiguration->withPeerName($serverName);
        }

        $sslContext = Internal\client_ssl_context($clientConfiguration);
        stream_context_set_options($resource, ['ssl' => $sslContext]);

        $cryptoMethod = Internal\crypto_method(
            $clientConfiguration->minimumVersion,
            $clientConfiguration->maximumVersion,
            server: false,
        );

        $state = Internal\enable_crypto($resource, $cryptoMethod, server: false, cancellation: $cancellation);

        $pins = $clientConfiguration->peerFingerprints;
        if (null !== $pins && !self::matchesFingerprint($state->peerCertificate, $pins)) {
            // The handshake has completed, so the plain stream must not be used any further.
            @stream_socket_enable_crypto($resource, false);
            $stream->close();

            throw new HandshakeFailedException(
                'TLS peer certificate does not match any of the configured SHA-256 fingerprints.',
            );
        }

        return new Internal\Stream($stream, $state);
    }

    /**
     * Whether the peer certificate's SHA-256 fingerprint matches one of the configured pins.
     *
     * Pins are hexadecimal; letter case and `:` separators are ignored.
     *
     * @param list<non-empty-string> $pins
     */
    private static function matchesFingerprint(null|PeerCertificate $peerCertificate, array $pins): bool
    {
        if (null === $peerCertificate || '' === $peerCertificate->fingerprint) {
            return false;
        }

        $fingerprint = $peerCertificate->fingerprint;

        foreach ($pins as $pin) {
            if (hash_equals($fingerprint, strtolower(str_replace(':', '', $pin)))) {
                return true;
            }
        }

        return false;
    }
}
