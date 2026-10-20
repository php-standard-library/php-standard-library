<?php

declare(strict_types=1);

namespace Psl\File;

use Psl\Async;
use Psl\IO;

use function sprintf;

/**
 * Reads entire file into a string.
 *
 * @param non-empty-string $file
 * @param int<0, max> $offset The offset where the reading starts.
 * @param positive-int|null $length Maximum length of data read.
 *                                  The default is to read until end of file is reached.
 *
 * @throws Exception\NotFoundException If $file does not exist, or contains a NUL byte.
 * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
 * @throws Exception\NotReadableException If $file exists, and is non-readable.
 * @throws Exception\RuntimeException In case of an error.
 * @throws Async\Exception\CancelledException If the operation is cancelled.
 *
 * @api
 */
function read(
    string $file,
    int $offset = 0,
    null|int $length = null,
    Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
): string {
    $cancellation->throwIfCancelled();

    try {
        $cancellation->throwIfCancelled();
        $handle = namespace\open_read_only($file);

        // the handle must be closed explicitly: it is kept alive by its event loop watchers.
        try {
            $cancellation->throwIfCancelled();
            $lock = $handle->lock(namespace\LockType::Shared, $cancellation);

            try {
                $cancellation->throwIfCancelled();
                $handle->seek($offset);

                $cancellation->throwIfCancelled();
                return $handle->readAll($length, $cancellation);
            } finally {
                $lock->release();
            }
        } finally {
            $handle->close();
        }
    } catch (IO\Exception\ExceptionInterface $previous) {
        // @codeCoverageIgnoreStart
        throw new Exception\RuntimeException(sprintf('Failed to read file "%s".', $file), 0, $previous);
        // @codeCoverageIgnoreEnd
    }
}
