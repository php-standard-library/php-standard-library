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
use function stream_socket_recvfrom;

use const PHP_OS_FAMILY;
use const STREAM_PEEK;

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
 * On Windows, PHP reports a step that failed on the socket as in progress: it tells a step
 * that waits from one that failed by `errno` (`ext/openssl/xp_ssl.c`,
 * `php_openssl_enable_crypto`), which OpenSSL resets before each socket call on POSIX
 * systems, but which on Windows, where OpenSSL uses the Winsock error instead, still holds
 * the `EAGAIN` that PHP stored when an earlier step had to wait. PHP fixed the same mistake
 * for reads only (php-src 614c62872ac). A step that waits emits no warning and finds the
 * connection open, so a step that returned 0 on Windows after emitting a warning, or that
 * finds the peer has ended the connection, has failed, and is reported as such instead of
 * waiting for a peer that is gone until the cancellation token ends the handshake.
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

        // On Windows, a step that failed may return 0; a peek of the raw socket returns '' once the peer has ended it.
        if (
            0 === $result
            && PHP_OS_FAMILY === 'Windows'
            && (null !== $native || '' === @stream_socket_recvfrom($stream, 1, STREAM_PEEK))
        ) {
            $result = false;
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
