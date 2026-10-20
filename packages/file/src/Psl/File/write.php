<?php

declare(strict_types=1);

namespace Psl\File;

use Psl\Async;
use Psl\IO;

use function clearstatcache;
use function sprintf;

/**
 * Write $content to $file.
 *
 * @param non-empty-string $file
 *
 * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
 * @throws Exception\AlreadyCreatedException If $file is already created, and $mode is {@see WriteMode::MustCreate}.
 * @throws Exception\NotWritableException If $file exists, and is non-writable.
 * @throws Exception\RuntimeException In case of an error.
 * @throws Exception\InvalidArgumentException If $file contains a NUL byte.
 * @throws Async\Exception\CancelledException If the operation is cancelled.
 *
 * @api
 */
function write(
    string $file,
    string $content,
    WriteMode $mode = WriteMode::OpenOrCreate,
    Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
): void {
    clearstatcache();

    try {
        $cancellation->throwIfCancelled();
        $handle = namespace\open_write_only($file, $mode);

        // the handle must be closed explicitly: it is kept alive by its event loop watchers.
        try {
            $cancellation->throwIfCancelled();
            $lock = $handle->lock(namespace\LockType::Exclusive, $cancellation);

            try {
                $cancellation->throwIfCancelled();
                $handle->writeAll($content, $cancellation);
            } finally {
                $lock->release();
            }
        } finally {
            $handle->close();
        }

        clearstatcache();
    } catch (IO\Exception\ExceptionInterface $previous) {
        // @codeCoverageIgnoreStart
        throw new Exception\RuntimeException(sprintf('Failed to write to file "%s".', $file), 0, $previous);
        // @codeCoverageIgnoreEnd
    }
}
