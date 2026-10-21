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
use function is_writable;
use function mkdir;
use function sprintf;
use function str_contains;

/**
 * @api
 */
final readonly class WriteHandle extends Internal\AbstractHandleWrapper implements WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private WriteHandleInterface $writeHandle;

    /**
     * @param non-empty-string $file
     *
     * @throws Exception\NotFileException If $file points to a non-file node on the filesystem.
     * @throws Exception\AlreadyCreatedException If $file is already created, and $writeMode is {@see WriteMode::MustCreate}.
     * @throws Exception\NotWritableException If $file is non-writable.
     * @throws Exception\RuntimeException If unable to create the $file if it does not exist.
     * @throws Exception\InvalidArgumentException If $file contains a NUL byte.
     * @throws IO\Exception\RuntimeException If the file cannot be opened, for example when the process has no file
     *  descriptors left.
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

        if ($isFile && !is_writable($file)) {
            throw Exception\NotWritableException::for($file);
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
        }

        $this->writeHandle = Internal\open($file, $writeMode->value, read: false, write: true);

        parent::__construct($this->writeHandle);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        return $this->writeHandle->tryWrite($bytes);
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->writeHandle->write($bytes, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->writeHandle->writeAll($bytes, $cancellation);
    }
}
