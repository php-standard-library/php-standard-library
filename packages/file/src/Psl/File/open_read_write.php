<?php

declare(strict_types=1);

namespace Psl\File;

/**
 * Open a file handle for read and write.
 *
 * @param non-empty-string $path
 *
 * @throws Exception\NotFileException If $path points to a non-file node on the filesystem.
 * @throws Exception\AlreadyCreatedException If $path is already created, and $writeMode is {@see WriteMode::MustCreate}.
 * @throws Exception\NotWritableException If $path exists, and is non-writable.
 * @throws Exception\NotReadableException If $path exists, and is non-readable.
 * @throws Exception\RuntimeException If unable to create the $path if it does not exist.
 * @throws Exception\InvalidArgumentException If $path contains a NUL byte.
 *
 * @api
 */
function open_read_write(
    string $path,
    WriteMode $writeMode = WriteMode::OpenOrCreate,
): ReadHandleInterface&WriteHandleInterface {
    return new ReadWriteHandle($path, $writeMode);
}
