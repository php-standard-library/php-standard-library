<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use OpenSSLCertificate;
use Psl\Network;
use Psl\TLS;

use function array_map;
use function stream_context_get_options;
use function stream_context_set_option;
use function stream_get_meta_data;

/**
 * Extract TLS connection state from a stream resource after a successful handshake.
 *
 * PHP captures the peer certificate into the stream's context, and streams accepted
 * from one listener may share that context. For server handshakes, the captured
 * `peer_certificate` and `peer_certificate_chain` options are therefore removed from the
 * context once read, so that a later connection on the same context that presents no
 * certificate cannot be reported with this connection's identity.
 *
 * @param resource $stream The stream resource with an active TLS session.
 * @param bool $server Whether this is a server handshake.
 *
 * @throws Network\Exception\RuntimeException If the negotiated protocol version is unknown.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function extract_connection_state(mixed $stream, bool $server): TLS\ConnectionState
{
    $meta = stream_get_meta_data($stream);
    /**
     * @var array{
     *  'protocol'?: string,
     *  'cipher_name'?: string,
     *  'cipher_bits'?: int,
     *  'cipher_version'?: string,
     *  'alpn_protocol'?: string,
     *  ...
     * } $crypto
     */
    $crypto = $meta['crypto'];

    $version = match ($crypto['protocol'] ?? '') {
        'TLSv1' => TLS\Version::Tls10,
        'TLSv1.1' => TLS\Version::Tls11,
        'TLSv1.2' => TLS\Version::Tls12,
        'TLSv1.3' => TLS\Version::Tls13,
        default => throw new Network\Exception\RuntimeException(
            'Unknown TLS protocol version: ' . ($crypto['protocol'] ?? '(empty)'),
        ),
    };

    $cipherName = $crypto['cipher_name'] ?? '';
    $cipherBits = $crypto['cipher_bits'] ?? 0;
    $cipherVersion = $crypto['cipher_version'] ?? '';
    $alpn = $crypto['alpn_protocol'] ?? '';
    $alpnProtocol = $alpn !== '' ? $alpn : null;

    $contextOptions = stream_context_get_options($stream);
    /**
     * @var array{
     *   peer_certificate?: OpenSSLCertificate,
     *   peer_certificate_chain?: list<OpenSSLCertificate>,
     *   ...
     * } $sslOptions
     */
    $sslOptions = $contextOptions['ssl'] ?? [];

    $peerCertificate = isset($sslOptions['peer_certificate'])
        ? namespace\parse_peer_certificate($sslOptions['peer_certificate'])
        : null;

    $peerCertificateChain = isset($sslOptions['peer_certificate_chain'])
        ? array_map(parse_peer_certificate(...), $sslOptions['peer_certificate_chain'])
        : null;

    if ($server) {
        stream_context_set_option($stream, 'ssl', 'peer_certificate', null);
        stream_context_set_option($stream, 'ssl', 'peer_certificate_chain', null);
    }

    return new TLS\ConnectionState(
        $version,
        $cipherName,
        $cipherBits,
        $cipherVersion,
        $alpnProtocol,
        $peerCertificate,
        $peerCertificateChain,
    );
}
