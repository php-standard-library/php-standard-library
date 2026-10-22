<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\TLS;
use Psl\TLS\Exception\HandshakeFailedException;

use function implode;

/**
 * Build an SSL context options array from {@see TLS\ServerConfiguration}.
 *
 * The returned array also resets `peer_certificate` and `peer_certificate_chain`. PHP only
 * writes these when the client presents a certificate; on a stream context shared between
 * accepted connections, a stale capture from a previous handshake would otherwise be read
 * back for a client that presented none.
 *
 * SNI certificates are passed as `array{local_cert, local_pk}` per host name, the only
 * shape PHP reads besides a single combined PEM path (`ext/openssl/xp_ssl.c`,
 * `php_openssl_enable_server_sni`). PHP loads their keys without a passphrase callback
 * (`php_openssl_create_sni_server_ctx`), so OpenSSL would prompt for the passphrase on the
 * process's terminal, wait there when one is attached, and fail every handshake of the
 * stream otherwise. A passphrase-protected SNI key is therefore refused before the
 * handshake starts.
 *
 * @throws HandshakeFailedException If an SNI certificate's private key is protected by a passphrase.
 *
 * @return array<string, mixed>
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function server_ssl_context(TLS\ServerConfiguration $serverConfiguration): array
{
    $ssl = [
        'local_cert' => $serverConfiguration->certificate->certificateFile,
        'local_pk' => $serverConfiguration->certificate->keyFile,
        'security_level' => $serverConfiguration->securityLevel,
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
        'peer_certificate' => null,
        'peer_certificate_chain' => null,
        'session_tickets' => $serverConfiguration->sessionTickets,
    ];

    if (null !== $serverConfiguration->certificate->passphrase) {
        $ssl['passphrase'] = $serverConfiguration->certificate->passphrase;
    }

    if (null !== $serverConfiguration->certificateAuthority) {
        $ssl['cafile'] = $serverConfiguration->certificateAuthority;
    }

    if (null !== $serverConfiguration->certificateAuthorityPath) {
        $ssl['capath'] = $serverConfiguration->certificateAuthorityPath;
    }

    if (null !== $serverConfiguration->ciphers) {
        $ssl['ciphers'] = $serverConfiguration->ciphers;
    }

    if (null !== $serverConfiguration->alpnProtocols) {
        $ssl['alpn_protocols'] = implode(',', $serverConfiguration->alpnProtocols);
    }

    if ([] !== $serverConfiguration->sniCertificates) {
        $sniCertificates = [];
        foreach ($serverConfiguration->sniCertificates as $hostname => $certificate) {
            if (null !== $certificate->passphrase) {
                throw new HandshakeFailedException(
                    "TLS handshake failed: the private key of the SNI certificate for \"{$hostname}\" is protected by a "
                    . 'passphrase, which PHP cannot apply to SNI certificates. Use LazyAcceptor, and complete the '
                    . 'handshake with a ServerConfiguration whose main certificate is that one.',
                );
            }

            $sniCertificates[$hostname] = [
                'local_cert' => $certificate->certificateFile,
                'local_pk' => $certificate->keyFile,
            ];
        }

        $ssl['SNI_server_certs'] = $sniCertificates;
    }

    return $ssl;
}
