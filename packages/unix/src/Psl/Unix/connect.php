<?php

declare(strict_types=1);

namespace Psl\Unix;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;

/**
 * Connect to a Unix domain socket.
 *
 * A path starting with a NUL byte names a socket in the Linux abstract namespace.
 *
 * @param non-empty-string $path
 *
 * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one, or is
 *                                                    longer than the system can hold.
 * @throws Network\Exception\RuntimeException If failed to connect on the given path, or if on Windows.
 * @throws CancelledException If the operation is cancelled.
 *
 * @api
 */
function connect(string $path, CancellationTokenInterface $cancellation = new NullCancellationToken()): StreamInterface
{
    Internal\assert_not_windows();

    Internal\assert_valid_path($path);

    $socket = Network\Internal\socket_connect("unix://{$path}", cancellation: $cancellation);

    return Internal\Stream::connected($socket, $path);
}
