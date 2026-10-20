<?php

declare(strict_types=1);

namespace Psl\File;

/**
 * Open a file handle for read and write.
 *
 * @param non-empty-string $path
 *
 * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
 * @throws Exception\AlreadyCreatedException If $file is already created, and $writeMode is {@see WriteMode::MUST_CREATE}.
 * @throws Exception\NotFoundException If $file does not exist, and $writeMode is {@see WriteMode::TRUNCATE} or {@see WriteMode::APPEND}.
 * @throws Exception\NotWritableException If $file exists, and is non-writable.
 * @throws Exception\NotReadableException If $file exists, and is non-readable.
 * @throws Exception\RuntimeException If unable to create the $file if it does not exist.
 * @throws Exception\InvalidArgumentException If $file contains a NUL byte.
 *
 * @api
 */
function open_read_write(
    string $path,
    WriteMode $writeMode = WriteMode::OpenOrCreate,
): ReadHandleInterface&WriteHandleInterface {
    return new ReadWriteHandle($path, $writeMode);
}
