<?php

declare(strict_types=1);

namespace Psl\Env;

use function getcwd;

/**
 * Returns the current working directory.
 *
 * @throws Exception\RuntimeException If unable to retrieve the current working directory, for example because
 *  it has been deleted.
 *
 * @return non-empty-string
 *
 * @api
 */
function current_dir(): string
{
    $directory = getcwd();
    if (false === $directory) {
        throw new Exception\RuntimeException('Unable to retrieve current working directory.');
    }

    return $directory;
}
