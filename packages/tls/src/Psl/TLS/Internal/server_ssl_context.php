<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\TLS;

use function implode;
use function stream_context_create;

/**
 * Build an SSL context options array from {@see TLS\ServerConfiguration}.
 *
 * The returned array also resets `peer_certificate` and `peer_certificate_chain`. PHP only
 * writes these when the client presents a certificate; on a stream context shared between
 * accepted connections, a stale capture from a previous handshake would otherwise be read
 * back for a client that presented none.
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
        $sniCerts = [];
        foreach ($serverConfiguration->sniCertificates as $hostname => $certificate) {
            $sniSsl = [
                'local_cert' => $certificate->certificateFile,
                'local_pk' => $certificate->keyFile,
            ];

            if (null !== $certificate->passphrase) {
                $sniSsl['passphrase'] = $certificate->passphrase;
            }

            $sniCerts[$hostname] = stream_context_create(['ssl' => $sniSsl]);
        }

        $ssl['SNI_server_certs'] = $sniCerts;
    }

    return $ssl;
}
