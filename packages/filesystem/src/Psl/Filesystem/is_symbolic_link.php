<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function is_link as php_is_link;
use function str_contains;

/**
 * Check whether $symbolicLink exists and is a symbolic link.
 *
 * A path that contains a NUL byte can never exist, so `false` is returned for it.
 *
 * @param non-empty-string $node Path, absolute or relative to the current working directory.
 *
 * @api
 */
function is_symbolic_link(string $node): bool
{
    return !str_contains($node, "\0") && php_is_link($node);
}
