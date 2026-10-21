<?php

declare(strict_types=1);

namespace Psl\IO;

use function error_get_last;
use function stream_socket_pair;

use const PHP_OS_FAMILY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_INET;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * Create a pair of handles, where writes to the {@see WriteHandleInterface} can be read from the {@see ReadHandleInterface}.
 *
 * @return list{
 *  CloseHandleInterface&ReadHandleInterface&StreamHandleInterface,
 *  CloseHandleInterface&WriteHandleInterface&StreamHandleInterface,
 * }
 *
 * @throws Exception\RuntimeException If the pair of connected sockets cannot be created, for example when the
 *  process has no file descriptors left.
 *
 * @api
 */
function pipe(): array
{
    $sockets = Internal\suppress(
        /**
         * @return array{0: resource, 1: resource}
         */
        static function (): array {
            $domain = PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX;
            $sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            // @codeCoverageIgnoreStart
            if (false === $sockets) {
                $error = error_get_last();

                throw new Exception\RuntimeException($error['message'] ?? 'Unable to create a pipe stream.');
            }

            // @codeCoverageIgnoreEnd

            return [$sockets[0], $sockets[1]];
        },
    );

    return [
        new CloseReadStreamHandle($sockets[0]),
        new CloseWriteStreamHandle($sockets[1]),
    ];
}
