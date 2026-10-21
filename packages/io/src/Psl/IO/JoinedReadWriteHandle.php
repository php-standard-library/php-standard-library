<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

/**
 * A handle that joins a separate reader and writer into a single read-write handle.
 *
 * All read operations ({@see read()}, {@see tryRead()}, {@see reachedEndOfDataSource()})
 * are delegated to the underlying {@see ReadHandleInterface}, while all write operations
 * ({@see write()}, {@see tryWrite()}) are delegated to the underlying {@see WriteHandleInterface}.
 *
 * When {@see close()} is called, both underlying handles are closed if they implement
 * {@see CloseHandleInterface}. After closing, all operations will throw
 * {@see Exception\AlreadyClosedException}.
 *
 * This is useful for combining independently-sourced read and write streams into a
 * unified handle, such as pairing a pipe's read end with a socket's write end.
 *
 * @api
 */
final class JoinedReadWriteHandle implements ReadHandleInterface, BufferedWriteHandleInterface, CloseHandleInterface
{
    private bool $closed = false;

    /**
     * Takes ownership of each handle that implements {@see CloseHandleInterface}: {@see close()} closes it. A
     * handle that does not is only borrowed.
     *
     * @param ReadHandleInterface $reader The handle to delegate read operations to.
     * @param WriteHandleInterface $writer The handle to delegate write operations to.
     */
    public function __construct(
        private readonly ReadHandleInterface $reader,
        private readonly WriteHandleInterface $writer,
    ) {}

    /**
     * Whether the underlying reader has reached the end of its data source.
     *
     * Delegates to {@see ReadHandleInterface::reachedEndOfDataSource()} on the reader.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        $this->assertHandleIsOpen();

        return $this->reader->reachedEndOfDataSource();
    }

    /**
     * Try to read from the underlying reader immediately, without waiting.
     *
     * Delegates to {@see ReadHandleInterface::tryRead()} on the reader.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to read, or null for no limit.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     *
     * @return string The read data on success, or an empty string if the handle is not ready for read.
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->assertHandleIsOpen();

        return $this->reader->tryRead($maxBytes);
    }

    /**
     * Read from the underlying reader, waiting for data if necessary.
     *
     * Delegates to {@see ReadHandleInterface::read()} on the reader.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to read, or null for no limit.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     *
     * @return string The read data on success, or an empty string if the end of data source is reached.
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        return $this->reader->read($maxBytes, $cancellation);
    }

    /**
     * Try to write to the underlying writer immediately, without waiting.
     *
     * Delegates to {@see WriteHandleInterface::tryWrite()} on the writer.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     *
     * @return int<0, max> The number of bytes written on success, which may be 0.
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        $this->assertHandleIsOpen();

        return $this->writer->tryWrite($bytes);
    }

    /**
     * Write data to the underlying writer, waiting if necessary.
     *
     * Delegates to {@see WriteHandleInterface::write()} on the writer.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     *
     * @return int<0, max> The number of bytes written, which may be less than the length of input string.
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $this->assertHandleIsOpen();

        return $this->writer->write($bytes, $cancellation);
    }

    /**
     * Read all remaining data from the underlying reader.
     *
     * Delegates to {@see ReadHandleInterface::readAll()} on the reader, preserving
     * any optimized implementation the reader may provide.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to read, or null for no limit.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     */
    #[Override]
    public function readAll(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        return $this->reader->readAll($maxBytes, $cancellation);
    }

    /**
     * Read a fixed number of bytes from the underlying reader.
     *
     * Delegates to {@see ReadHandleInterface::readFixedSize()} on the reader, preserving
     * any optimized implementation the reader may provide.
     *
     * @param positive-int $size The exact number of bytes to read.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation or fewer bytes are available.
     */
    #[Override]
    public function readFixedSize(
        int $size,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        return $this->reader->readFixedSize($size, $cancellation);
    }

    /**
     * Write all bytes to the underlying writer, retrying until all data is written.
     *
     * Delegates to {@see WriteHandleInterface::writeAll()} on the writer, preserving
     * any optimized implementation the writer may provide.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     */
    #[Override]
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->assertHandleIsOpen();

        $this->writer->writeAll($bytes, $cancellation);
    }

    public function flush(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        $this->assertHandleIsOpen();

        if ($this->writer instanceof BufferedWriteHandleInterface) {
            $this->writer->flush($cancellation);
        }
    }

    /**
     * Whether the handle has been closed.
     */
    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close the handle, closing both the underlying reader and writer if they implement {@see CloseHandleInterface}.
     *
     * Both handles are closed even if closing the reader fails, and a handle passed as both is closed once; the
     * first failure is then rethrown. Closing the handle again does nothing.
     *
     * After closing, all read and write operations will throw {@see Exception\AlreadyClosedException}.
     *
     * @throws Exception\RuntimeException If unable to close one of the underlying handles.
     */
    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        Internal\close_all($this->reader, $this->writer);
    }

    /**
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    private function assertHandleIsOpen(): void
    {
        if ($this->closed) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        $this->close();
    }
}
