<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function is_dir;
use function str_contains;

/**
 * Check whether $node exists and is a directory.
 *
 * A path that contains a NUL byte can never exist, so `false` is returned for it.
 *
 * @param string $node Path, absolute or relative to the current working directory.
 *                     If it is a link, it will be resolved and checked.
 *
 * @psalm-assert-if-true =non-empty-string $node
 *
 * @api
 */
function is_directory(string $node): bool
{
    return !str_contains($node, "\0") && is_dir($node);
}
