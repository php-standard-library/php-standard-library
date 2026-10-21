<?php

declare(strict_types=1);

namespace Psl\Network\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network\Exception;
use Revolt\EventLoop;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function is_resource;
use function str_starts_with;
use function stream_context_create;
use function stream_socket_client;
use function stream_socket_get_name;

use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;

/**
 * Open a socket connected to the given address, waiting until the connect completes.
 *
 * A TCP connect is asynchronous: it ends once the socket becomes writable, whether it succeeded or not, and a socket
 * that has no peer address then failed. The wait has a record of its own, which the watcher and the cancellation
 * listener settle, whichever comes first. A connect that does not complete, because it failed or was cancelled,
 * closes its socket.
 *
 * A Unix-domain connect never completes later: it returns its socket at once, without the wait or the check, or it
 * has already failed, as when the listener's backlog is full.
 *
 * @param non-empty-string $uri
 * @param array<string, mixed> $context
 *
 * @throws Exception\RuntimeException If failed to connect to client on the given address.
 * @throws CancelledException If the operation was cancelled.
 *
 * @return resource
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function socket_connect(
    string $uri,
    array $context = [],
    CancellationTokenInterface $cancellation = new NullCancellationToken(),
): mixed {
    $cancellable = $cancellation->cancellable;
    if ($cancellable) {
        $cancellation->throwIfCancelled();
    }

    $errorMessage = '';
    $errorCode = 0;

    $context = stream_context_create($context);
    $socket = @stream_socket_client(
        $uri,
        $errorCode,
        $errorMessage,
        null,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        $context,
    );

    if (!$socket || $errorCode) {
        throw new Exception\RuntimeException(
            'Failed to connect to client "'
            . $uri
            . '": '
            . ($errorMessage !== null && $errorMessage !== '' ? $errorMessage : 'error ' . (int) $errorCode)
            . '.',
            (int) $errorCode,
        );
    }

    // A Unix-domain connect never completes later: it has connected, or it has already failed above, as when the
    // listener's backlog is full (EAGAIN on Linux, ECONNREFUSED on macOS). The completion check below would only
    // misread its socket, as PHP reports no peer name for a socket in the Linux abstract namespace.
    if (str_starts_with($uri, 'unix://')) {
        return $socket;
    }

    // The connect ends, whether it failed or not, once the socket becomes writable. The wait has its own record,
    // which the watcher and the cancellation listener settle: the listener may run during subscribe(), before the
    // wait has suspended, or after the wait has ended, when the fiber may already be waiting for something else.
    $waiter = new Waiter();
    $watcher = EventLoop::onWritable($socket, static function () use ($waiter): void {
        $waiter->resume();
    });

    $id = null;
    $connected = false;

    try {
        if ($cancellable) {
            $id = $cancellation->subscribe(static function (CancelledException $exception) use ($waiter): void {
                $waiter->fail($exception);
            });
        }

        $waiter->wait();

        error_clear_last();
        if (false === @stream_socket_get_name($socket, true)) {
            $error = error_get_last();

            throw new Exception\RuntimeException(
                'Failed to connect to client "'
                . $uri
                . '": '
                . ($error['message'] ?? 'the socket has no peer address.'),
                $error['type'] ?? 0,
            );
        }

        $connected = true;

        return $socket;
    } finally {
        EventLoop::cancel($watcher);
        if (null !== $id) {
            $cancellation->unsubscribe($id);
        }

        if (!$connected && is_resource($socket)) {
            fclose($socket);
        }
    }
}
