<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function is_writable as php_is_writable;
use function str_contains;

/**
 * Check whether $node exists and is writable.
 *
 * A path that contains a NUL byte can never exist, so `false` is returned for it.
 *
 * @param non-empty-string $node Path, absolute or relative to the current working directory.
 *
 * @api
 */
function is_writable(string $node): bool
{
    return !str_contains($node, "\0") && php_is_writable($node);
}
