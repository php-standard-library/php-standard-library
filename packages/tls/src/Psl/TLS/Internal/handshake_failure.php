<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\TLS\Exception\HandshakeFailedException;
use Psl\TLS\Exception\PeerVerificationException;

use function preg_replace;

/**
 * Build the exception for a failed native handshake from the warning PHP emitted.
 *
 * Certificate verification failures become {@see PeerVerificationException} so that retry
 * decorators do not repeat them; see {@see is_verification_failure()} for how they are told
 * apart. The warning text is kept in the message, without PHP's function-name prefix.
 *
 * @param null|string $native The warning message emitted by stream_socket_enable_crypto(), if any.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function handshake_failure(null|string $native): HandshakeFailedException
{
    if (null === $native || '' === $native) {
        return new HandshakeFailedException('TLS handshake failed.');
    }

    $message =
        'TLS handshake failed: ' . (preg_replace('/^stream_socket_enable_crypto\(\): /', '', $native) ?? $native);

    if (namespace\is_verification_failure($native)) {
        return new PeerVerificationException($message);
    }

    return new HandshakeFailedException($message);
}
