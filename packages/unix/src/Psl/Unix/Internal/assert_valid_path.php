<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Psl\Network;

use function str_contains;
use function str_starts_with;
use function strlen;

/**
 * Assert that a Unix socket path would be used as given.
 *
 * A NUL byte ends a filesystem socket path, so everything after it would be silently dropped.
 * A path starting with a NUL byte names a socket in the Linux abstract namespace instead, and
 * every byte of it, NUL bytes included, is part of the name. A path longer than the system can
 * hold would be cut short, see {@see PathLimit}.
 *
 * @param non-empty-string $path
 *
 * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one, or is
 *                                                    longer than the system can hold.
 *
 * @internal
 */
function assert_valid_path(string $path): void
{
    if (!str_starts_with($path, "\0") && str_contains($path, "\0")) {
        throw new Network\Exception\InvalidArgumentException('Unix socket path must not contain NULL bytes.');
    }

    $maximum = PathLimit::maximumLength($path);
    if (null !== $maximum && strlen($path) > $maximum) {
        throw new Network\Exception\InvalidArgumentException(
            'Unix socket path must not be longer than ' . $maximum . ' bytes on this system.',
        );
    }
}
