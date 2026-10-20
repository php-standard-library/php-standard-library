<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function str_contains;

/**
 * Create the directory where the $filename is or will be stored.
 *
 * @param non-empty-string $filename
 *
 * @throws Exception\RuntimeException If unable to create the directory.
 * @throws Exception\InvalidArgumentException If $filename contains a NUL byte.
 *
 * @return non-empty-string
 *
 * @api
 */
function create_directory_for_file(string $filename, int $permissions = 0o777): string
{
    if (str_contains($filename, "\0")) {
        throw new Exception\InvalidArgumentException('$filename should not contain a NUL byte.');
    }

    $directory = namespace\get_directory($filename);
    namespace\create_directory($directory, $permissions);

    return $directory;
}
