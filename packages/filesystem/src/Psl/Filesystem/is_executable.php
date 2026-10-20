<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function is_executable as php_is_executable;
use function str_contains;

/**
 * Check whether $node exists and is an executable file
 * or a directory with `execute` permission.
 *
 * A path that contains a NUL byte can never exist, so `false` is returned for it.
 *
 * @param non-empty-string $node Path, absolute or relative to the current working directory.
 *
 * @api
 */
function is_executable(string $node): bool
{
    return !str_contains($node, "\0") && php_is_executable($node);
}
