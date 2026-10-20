<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Psl\Network;

use function str_contains;
use function str_starts_with;

/**
 * Assert that a Unix socket path would be used as given.
 *
 * A NUL byte ends a filesystem socket path, so everything after it would be silently dropped.
 * A path starting with a NUL byte names a socket in the Linux abstract namespace instead, and
 * every byte of it, NUL bytes included, is part of the name.
 *
 * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one.
 *
 * @internal
 */
function assert_valid_path(string $path): void
{
    if (!str_starts_with($path, "\0") && str_contains($path, "\0")) {
        throw new Network\Exception\InvalidArgumentException('Unix socket path must not contain NULL bytes.');
    }
}
