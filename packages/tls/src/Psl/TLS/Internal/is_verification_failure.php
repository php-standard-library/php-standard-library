<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use function hexdec;
use function preg_match_all;
use function str_contains;

/**
 * Whether the warning PHP emitted for a failed handshake reports a certificate verification failure.
 *
 * Two sources are recognised, both numeric codes or PHP's fixed strings rather than free text:
 *
 * - OpenSSL's packed error code (`error:XXXXXXXX:...`) when it decodes to ERR_LIB_SSL (20)
 *   with reason SSL_R_CERTIFICATE_VERIFY_FAILED (134). OpenSSL 3 packs it as
 *   `lib << 23 | reason` (0A000086), OpenSSL 1.1.1 as `lib << 24 | func << 12 | reason`
 *   (1416F086); both packings are decoded.
 * - PHP's own post-handshake verification messages (ext/openssl/xp_ssl.c,
 *   `php_openssl_apply_peer_verification_policy` and the checks it calls), which PHP 8.5
 *   and later classify as AuthFailed or NetworkRecvFailed stream errors.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function is_verification_failure(string $native): bool
{
    foreach ([
        'Could not verify peer',
        'Could not get peer certificate',
        'did not match expected CN',
        'Unable to locate peer certificate CN',
        'Peer certificate CN=',
        'peer_fingerprint',
        'Error verifying certificate chain policy',
    ] as $marker) {
        if (str_contains($native, $marker)) {
            return true;
        }
    }

    $matches = [];
    if (0 === (int) preg_match_all('/error:([0-9A-Fa-f]{8}):/', $native, $matches)) {
        return false;
    }

    foreach ($matches[1] as $hex) {
        $code = (int) hexdec($hex);
        // OpenSSL 3: ERR_PACK(lib, 0, reason) = (lib & 0xFF) << 23 | (reason & 0x7FFFFF).
        if (20 === (($code >> 23) & 0xFF) && 134 === ($code & 0x7F_FFFF)) {
            return true;
        }

        // OpenSSL 1.1.1: ERR_PACK(lib, func, reason) = lib << 24 | func << 12 | reason.
        if (20 === ($code >> 24) && 134 === ($code & 0xFFF)) {
            return true;
        }
    }

    return false;
}
