<?php

declare(strict_types=1);

namespace Psl\Network\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network\Exception;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function fclose;
use function is_resource;
use function str_starts_with;
use function stream_context_create;
use function stream_socket_client;
use function stream_socket_get_name;

use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;

/**
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
    return namespace\suppress(
        /**
         * @return resource
         */
        static function () use ($uri, $context, $cancellation): mixed {
            if ($cancellation->cancellable) {
                $cancellation->throwIfCancelled();
            }

            $_ = null;
            $errorCode = null;

            $context = stream_context_create($context);
            $socket = @stream_socket_client(
                $uri,
                $errorCode,
                $_,
                null,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                $context,
            );

            if (!$socket || $errorCode) {
                throw new Exception\RuntimeException('Failed to connect to client "' . $uri . '".', (int) $errorCode);
            }

            /** @var Suspension<resource> */
            $suspension = EventLoop::getSuspension();

            $writeWatcher = '';
            $cancellationId = null;
            if ($cancellation->cancellable) {
                $cancellationId = $cancellation->subscribe(static function (CancelledException $exception) use (
                    $suspension,
                    &$writeWatcher,
                    $socket,
                ): void {
                    EventLoop::cancel($writeWatcher);

                    if (is_resource($socket)) {
                        fclose($socket);
                    }

                    $suspension->throw($exception);
                });
            }

            $writeWatcher = EventLoop::onWritable($socket, static function () use (
                $suspension,
                $socket,
                $uri,
                $cancellation,
                &$cancellationId,
            ): void {
                if (null !== $cancellationId) {
                    $cancellation->unsubscribe($cancellationId);
                }

                // A Unix-domain connect has completed by the time the socket exists, and PHP reports no peer name
                // for a socket in the Linux abstract namespace, so only a TCP connect is checked for its peer.
                if (!str_starts_with($uri, 'unix://') && false === @stream_socket_get_name($socket, true)) {
                    fclose($socket);
                    $suspension->throw(new Exception\RuntimeException('Failed to connect to client "' . $uri . '".'));

                    return;
                }

                $suspension->resume($socket);
            });

            try {
                return $suspension->suspend();
            } finally {
                EventLoop::cancel($writeWatcher);
                if (null !== $cancellationId) {
                    $cancellation->unsubscribe($cancellationId);
                }
            }
        },
    );
}
