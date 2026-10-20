<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function is_file as php_is_file;
use function str_contains;

/**
 * Check whether $node exists and is a regular file or a link to one.
 *
 * A path that contains a NUL byte can never exist, so `false` is returned for it.
 *
 * @param non-empty-string $node Path, absolute or relative to the current working directory.
 *                               If it is a link, it will be resolved and checked.
 *
 * @api
 */
function is_file(string $node): bool
{
    return !str_contains($node, "\0") && php_is_file($node);
}
