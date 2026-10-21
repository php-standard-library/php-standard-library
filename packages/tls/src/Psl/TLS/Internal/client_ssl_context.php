<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\TLS;

use function implode;

/**
 * Build an SSL context options array from {@see TLS\ClientConfiguration}.
 *
 * @return array<string, mixed>
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function client_ssl_context(TLS\ClientConfiguration $clientConfiguration): array
{
    $ssl = [
        'verify_peer' => $clientConfiguration->peerVerification,
        'verify_peer_name' => $clientConfiguration->peerNameVerification ?? $clientConfiguration->peerVerification,
        'allow_self_signed' => $clientConfiguration->allowSelfSigned,
        'security_level' => $clientConfiguration->securityLevel,
        'capture_peer_cert' => true,
        'capture_peer_cert_chain' => true,
        'session_tickets' => $clientConfiguration->sessionTickets,
        'SNI_enabled' => $clientConfiguration->sniEnabled,
        'verify_depth' => $clientConfiguration->verificationDepth,
    ];

    if (null !== $clientConfiguration->peerName) {
        $ssl['peer_name'] = $clientConfiguration->peerName;
    }

    if (null !== $clientConfiguration->certificateAuthority) {
        $ssl['cafile'] = $clientConfiguration->certificateAuthority;
    }

    if (null !== $clientConfiguration->certificateAuthorityPath) {
        $ssl['capath'] = $clientConfiguration->certificateAuthorityPath;
    }

    if (null !== $clientConfiguration->certificate) {
        $ssl['local_cert'] = $clientConfiguration->certificate->certificateFile;
        $ssl['local_pk'] = $clientConfiguration->certificate->keyFile;
        if (null !== $clientConfiguration->certificate->passphrase) {
            $ssl['passphrase'] = $clientConfiguration->certificate->passphrase;
        }
    }

    if (null !== $clientConfiguration->minimumVersion || null !== $clientConfiguration->maximumVersion) {
        $ssl['crypto_method'] = namespace\crypto_method(
            $clientConfiguration->minimumVersion,
            $clientConfiguration->maximumVersion,
            server: false,
        );
    }

    if (null !== $clientConfiguration->ciphers) {
        $ssl['ciphers'] = $clientConfiguration->ciphers;
    }

    if (null !== $clientConfiguration->alpnProtocols) {
        $ssl['alpn_protocols'] = implode(',', $clientConfiguration->alpnProtocols);
    }

    // `peerFingerprints` is deliberately not mapped to `peer_fingerprint`: PHP accepts one
    // fingerprint string per algorithm there, never a list (ext/openssl/xp_ssl.c,
    // php_openssl_x509_fingerprint_match). The pins are checked by Connector::connect()
    // against the captured peer certificate once the handshake has completed.

    return $ssl;
}
