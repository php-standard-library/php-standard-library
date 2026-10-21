<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Network;
use Psl\TLS;
use Psl\TLS\Exception\HandshakeFailedException;

use function is_resource;
use function stream_context_get_options;
use function stream_context_set_options;

/**
 * Perform a TLS server handshake on an accepted plain stream.
 *
 * Streams accepted with `stream_socket_accept()` share the listening socket's stream
 * context, and PHP reads the server's SSL options from that context when the handshake
 * starts (`ext/openssl/xp_ssl.c`, `php_openssl_setup_crypto`). Options are merged into a
 * context; nothing can remove one. A trust setting written for one server configuration
 * therefore stays in effect for a later configuration that does not set it, which would
 * let that configuration accept client certificates it never asked to trust. Such a
 * stream is refused rather than upgraded. `TCP\listen()` gives every accepted connection
 * a private context when ext-sockets is available, in which case this cannot happen.
 *
 * @throws HandshakeFailedException If the TLS handshake fails.
 * @throws Network\Exception\RuntimeException If the stream is not available, or if its context
 *                                            carries client certificate trust settings that the
 *                                            given configuration does not set.
 * @throws CancelledException If the cancellation token is cancelled during the handshake.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function upgrade_server(
    Network\StreamInterface $stream,
    TLS\ServerConfiguration $serverConfiguration,
    CancellationTokenInterface $cancellation,
): TLS\StreamInterface {
    $resource = $stream->getStream();
    if (!is_resource($resource)) {
        throw new Network\Exception\RuntimeException('Stream resource is not available.');
    }

    $sslContext = namespace\server_ssl_context($serverConfiguration);

    /** @var array<string, mixed> $existing */
    $existing = stream_context_get_options($resource)['ssl'] ?? [];
    foreach (['cafile', 'capath'] as $trustOption) {
        if (isset($existing[$trustOption]) && !isset($sslContext[$trustOption])) {
            throw new Network\Exception\RuntimeException(
                "The stream context already carries the \"{$trustOption}\" TLS option, which this server configuration does not set. "
                . 'Accepted streams share the listener\'s stream context unless ext-sockets is available, so a client certificate '
                . 'trust setting from a previous handshake would apply to this one. Install ext-sockets, or use one listener '
                . 'per server configuration.',
            );
        }
    }

    stream_context_set_options($resource, ['ssl' => $sslContext]);

    $cryptoMethod = namespace\crypto_method(
        $serverConfiguration->minimumVersion,
        $serverConfiguration->maximumVersion,
        server: true,
    );

    $state = namespace\enable_crypto($resource, $cryptoMethod, server: true, cancellation: $cancellation);

    return new Stream($stream, $state);
}
