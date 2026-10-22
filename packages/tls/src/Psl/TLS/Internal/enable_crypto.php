<?php

declare(strict_types=1);

namespace Psl\TLS\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Psl\TLS;
use Psl\TLS\Exception\HandshakeFailedException;

use function restore_error_handler;
use function set_error_handler;
use function stream_socket_enable_crypto;

/**
 * Perform an asynchronous TLS handshake on the given stream resource.
 *
 * This function enables crypto on a stream using Revolt's event loop for non-blocking I/O.
 * When {@see stream_socket_enable_crypto()} returns 0 (handshake in progress), it suspends
 * until the stream becomes readable, or a short pause ends in case the handshake waits to
 * write (see {@see HandshakeWaiter}), then takes the next handshake step in the same fiber.
 *
 * The connection state is read in the same step that observes the completed handshake,
 * before any other fiber or event-loop callback can run. This matters for server
 * handshakes: streams accepted from one listener may share a single stream context, and
 * PHP captures the peer certificate into that context (`ext/openssl/xp_ssl.c`,
 * `php_openssl_capture_peer_certs`). Reading it later would attribute a certificate
 * captured by another connection's handshake to this one.
 *
 * A failed handshake is reported with the warning PHP emitted for it, through
 * {@see handshake_failure()}, which tells certificate verification failures apart.
 *
 * @param resource $stream The stream resource to enable TLS on.
 * @param int $cryptoMethod The crypto method bitmask (STREAM_CRYPTO_METHOD_*).
 * @param bool $server Whether this is a server handshake, see {@see extract_connection_state()}.
 *
 * @throws HandshakeFailedException If the TLS handshake fails.
 * @throws Network\Exception\RuntimeException If the negotiated protocol version is unknown.
 * @throws CancelledException If the cancellation token is cancelled during the handshake.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function enable_crypto(
    mixed $stream,
    int $cryptoMethod,
    bool $server,
    CancellationTokenInterface $cancellation = new NullCancellationToken(),
): TLS\ConnectionState {
    if ($cancellation->cancellable) {
        $cancellation->throwIfCancelled();
    }

    /**
     * One handshake step, capturing the warning PHP emits when the step fails.
     *
     * @return array{bool|int, null|string}
     */
    $step = static function () use ($stream, $cryptoMethod): array {
        $native = null;
        set_error_handler(static function (int $_, string $message) use (&$native): bool {
            $native = $message;

            return true;
        });

        try {
            // The stream is already non-blocking from ResourceHandle.
            $result = stream_socket_enable_crypto($stream, true, $cryptoMethod);
        } finally {
            restore_error_handler();
        }

        return [$result, $native];
    };

    [$result, $native] = $step();

    // A step that returns 0 is in progress: wait for the socket, then take the next step in this fiber.
    $waiter = new HandshakeWaiter($stream, $cancellation);
    while (0 === $result) {
        $waiter->wait();
        [$result, $native] = $step();
    }

    if (true === $result) {
        return namespace\extract_connection_state($stream, $server);
    }

    throw namespace\handshake_failure($native);
}
