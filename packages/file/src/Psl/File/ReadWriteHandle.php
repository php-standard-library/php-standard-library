<?php

declare(strict_types=1);

namespace Psl\File;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function dirname;
use function file_exists;
use function is_dir;
use function is_file;
use function is_readable;
use function is_writable;
use function mkdir;
use function sprintf;
use function str_contains;

/**
 * @api
 */
final readonly class ReadWriteHandle extends Internal\AbstractHandleWrapper implements
    WriteHandleInterface,
    ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;
    use IO\WriteHandleConvenienceMethodsTrait;

    private ReadHandleInterface&WriteHandleInterface $readWriteHandle;

    /**
     * @param non-empty-string $file
     *
     * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
     * @throws Exception\AlreadyCreatedException If $file is already created, and $writeMode is {@see WriteMode::MUST_CREATE}.
     * @throws Exception\NotFoundException If $file does not exist, and $writeMode is {@see WriteMode::TRUNCATE} or {@see WriteMode::APPEND}.
     * @throws Exception\NotWritableException If $file exists, and is non-writable
     * @throws Exception\NotReadableException If $file exists, and is non-readable.
     * @throws Exception\RuntimeException If unable to create the $file if it does not exist.
     * @throws Exception\InvalidArgumentException If $file contains a NUL byte.
     */
    public function __construct(string $file, WriteMode $writeMode = WriteMode::OpenOrCreate)
    {
        if (str_contains($file, "\0")) {
            throw new Exception\InvalidArgumentException('$file should not contain a NUL byte.');
        }

        $isFile = is_file($file);
        if (!$isFile && file_exists($file)) {
            throw Exception\NotFileException::for($file);
        }

        $mustCreate = $writeMode === WriteMode::MustCreate;
        if ($mustCreate && $isFile) {
            throw Exception\AlreadyCreatedException::for($file);
        }

        if ($isFile) {
            if (!is_writable($file)) {
                throw Exception\NotWritableException::for($file);
            }

            if (!is_readable($file)) {
                throw Exception\NotReadableException::for($file);
            }
        }

        if (!$isFile) {
            $directory = dirname($file);
            if (!is_dir($directory)) {
                $mkdir = Internal\suppress(static fn() => mkdir($directory, 0o777, true));
                if (!$mkdir && !is_dir($directory)) {
                    throw new Exception\RuntimeException(sprintf(
                        'Failed to create the directory for file "%s".',
                        $file,
                    ));
                }
            }

            if (!is_writable($directory)) {
                throw Exception\NotWritableException::for($file);
            }

            if (!is_readable($directory)) {
                // @codeCoverageIgnoreStart
                throw Exception\NotReadableException::for($file);
                // @codeCoverageIgnoreEnd
            }
        }

        $this->readWriteHandle = Internal\open($file, $writeMode->value . 'r+', read: true, write: true);

        parent::__construct($this->readWriteHandle);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->readWriteHandle->reachedEndOfDataSource();
    }

    /**
     * @param ?positive-int $maxBytes the maximum number of bytes to read
     *
     * @inheritDoc
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->readWriteHandle->tryRead($maxBytes);
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
        return $this->readWriteHandle->read($maxBytes, $cancellation);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        return $this->readWriteHandle->tryWrite($bytes);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->readWriteHandle->write($bytes, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->readWriteHandle->writeAll($bytes, $cancellation);
    }
}
