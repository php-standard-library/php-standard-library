<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

use function min;
use function strlen;

/**
 * A read handle that wraps another {@see ReadHandleInterface} and reads up to
 * a fixed number of bytes total.
 *
 * Once the byte limit is reached, the handle silently reports EOF regardless of
 * whether the underlying handle has more data available. This is useful for
 * constraining reads to a known boundary without consuming or validating the
 * remainder of the underlying stream.
 *
 * For a variant that throws when the underlying handle has more data than
 * the limit allows, see {@see BoundedReadHandle}.
 *
 * @api
 */
final class TruncatedReadHandle implements ReadHandleInterface, CloseHandleInterface
{
    use ReadHandleConvenienceMethodsTrait;

    private bool $closed = false;
    private int $bytesRead = 0;

    /**
     * Takes ownership of `$handle` if it implements {@see CloseHandleInterface}: {@see close()} closes it.
     * Otherwise it is only borrowed.
     *
     * @param ReadHandleInterface $handle The underlying handle to read from.
     * @param int $limit The maximum total number of bytes to read from the underlying handle.
     */
    public function __construct(
        private readonly ReadHandleInterface $handle,
        private readonly int $limit,
    ) {}

    /**
     * Whether the byte limit has been reached or the underlying handle has been exhausted.
     *
     * Returns {@see true} when the total number of bytes read equals the configured
     * limit, or when the underlying handle itself reports EOF. Returns {@see false}
     * otherwise.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        $this->assertHandleIsOpen();

        return $this->bytesRead >= $this->limit || $this->handle->reachedEndOfDataSource();
    }

    /**
     * Return data immediately available from the underlying handle, capped to the remaining allowance.
     *
     * If the byte limit has already been reached, returns an empty string immediately.
     * Otherwise, delegates to the underlying handle's {@see tryRead()} with
     * $maxBytes capped to the remaining byte allowance.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for the remaining allowance.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->assertHandleIsOpen();

        $remaining = $this->limit - $this->bytesRead;
        if ($remaining <= 0) {
            return '';
        }

        $cappedMax = $maxBytes === null ? $remaining : min($maxBytes, $remaining);

        $data = $this->handle->tryRead($cappedMax);
        $this->bytesRead += strlen($data);

        return $data;
    }

    /**
     * Read from the underlying handle, capped to the remaining byte allowance.
     *
     * If the byte limit has already been reached, returns an empty string immediately.
     * Otherwise, delegates to the underlying handle's {@see read()} with
     * $maxBytes capped to the remaining byte allowance and tracks the bytes read.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for the remaining allowance.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If the underlying handle encounters an error.
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        $remaining = $this->limit - $this->bytesRead;
        if ($remaining <= 0) {
            return '';
        }

        $cappedMax = $maxBytes === null ? $remaining : min($maxBytes, $remaining);

        $data = $this->handle->read($cappedMax, $cancellation);
        $this->bytesRead += strlen($data);

        return $data;
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
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause - A destructor cannot report an expected close failure (E11).
        }
    }

    /**
     * Close the handle.
     *
     * If the underlying handle implements {@see CloseHandleInterface}, it will be
     * closed as well. Closing the handle again does nothing. After closing, all
     * read operations will throw {@see Exception\AlreadyClosedException}.
     *
     * @throws Exception\RuntimeException If unable to close the underlying handle.
     */
    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->handle instanceof CloseHandleInterface) {
            $this->handle->close();
        }
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
}
