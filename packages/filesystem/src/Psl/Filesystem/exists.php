<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function file_exists;
use function str_contains;

/**
 * Check whether $node exists.
 *
 * A path that contains a NUL byte can never exist, so `false` is returned for it.
 *
 * @param string $node Path, absolute or relative to the current working directory.
 *
 * @psalm-assert-if-true =non-empty-string $node
 *
 * @api
 */
function exists(string $node): bool
{
    return !str_contains($node, "\0") && file_exists($node);
}
