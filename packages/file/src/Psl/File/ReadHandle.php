<?php

declare(strict_types=1);

namespace Psl\File;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function file_exists;
use function is_file;
use function is_readable;
use function str_contains;

/**
 * @api
 */
final readonly class ReadHandle extends Internal\AbstractHandleWrapper implements ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private ReadHandleInterface $readHandle;

    /**
     * @param non-empty-string $file
     *
     * @throws Exception\NotFoundException If $file does not exist, or contains a NUL byte.
     * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
     * @throws Exception\NotReadableException If $file exists, and is non-readable.
     * @throws IO\Exception\RuntimeException If the file cannot be opened, for example when the process has no file
     *  descriptors left.
     */
    public function __construct(string $file)
    {
        if (str_contains($file, "\0") || !file_exists($file)) {
            throw Exception\NotFoundException::for($file);
        }

        if (!is_file($file)) {
            throw Exception\NotFileException::for($file);
        }

        if (!is_readable($file)) {
            throw Exception\NotReadableException::for($file);
        }

        $this->readHandle = Internal\open($file, 'r', read: true, write: false);

        parent::__construct($this->readHandle);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->readHandle->reachedEndOfDataSource();
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @inheritDoc
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->readHandle->tryRead($maxBytes);
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @inheritDoc
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->readHandle->read($maxBytes, $cancellation);
    }
}
