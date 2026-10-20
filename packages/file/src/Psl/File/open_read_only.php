<?php

declare(strict_types=1);

namespace Psl\File;

/**
 * Open a file handle for read only.
 *
 * @param non-empty-string $file
 *
 * @throws Exception\NotFoundException If $file does not exist, or contains a NUL byte.
 * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
 * @throws Exception\NotReadableException If $file exists, and is non-readable.
 *
 * @api
 */
function open_read_only(string $file): ReadHandle
{
    return new ReadHandle($file);
}
