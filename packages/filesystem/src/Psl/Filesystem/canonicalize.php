<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function realpath;
use function str_contains;

/**
 * Returns canonicalized absolute pathname.
 * The resulting path will have no symbolic links, '/./' or '/../' components.
 *
 * A path that contains a NUL byte can never exist, so `null` is returned for it.
 *
 * @return non-empty-string|null
 *
 * @api
 */
function canonicalize(string $path): null|string
{
    if (str_contains($path, "\0")) {
        return null;
    }

    $path = realpath($path);

    return false !== $path && '' !== $path ? $path : null;
}
