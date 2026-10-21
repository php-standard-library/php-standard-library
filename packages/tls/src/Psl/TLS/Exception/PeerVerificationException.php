<?php

declare(strict_types=1);

namespace Psl\TLS\Exception;

use Psl\Network;

/**
 * Exception thrown when a TLS handshake fails because the peer's certificate was rejected.
 *
 * Covers certificate chain verification (an unknown authority, an expired or self-signed
 * certificate), peer name verification, fingerprint pinning, and a missing peer certificate
 * where one is required. It is a {@see HandshakeFailedException}, so existing handlers keep
 * matching, and it is non-retryable: the same certificate fails the next handshake too.
 *
 * @api
 */
final class PeerVerificationException extends HandshakeFailedException implements
    Network\Exception\NonRetryableExceptionInterface {}
