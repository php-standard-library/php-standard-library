<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

use function min;
use function strlen;

/**
 * A read handle that wraps another {@see ReadHandleInterface} and enforces a
 * maximum byte limit.
 *
 * Reads up to $limit bytes from the underlying handle. Once the limit is
 * reached, the handle peeks one additional byte from the underlying handle to
 * distinguish between "data fits exactly" (reports EOF) and "data exceeds
 * limit" (throws {@see Exception\RuntimeException}). {@see read()} waits for
 * that byte; {@see tryRead()} only inspects data that is already available and
 * returns an empty string, without reporting EOF, while the underlying handle
 * has nothing to offer yet. EOF is reported only when the underlying handle
 * reports it.
 *
 * This is useful for enforcing size limits where exceeding the boundary is a
 * violation (e.g., HTTP response body size limits, protocol frame sizes).
 *
 * For a variant that silently truncates at the limit without checking for
 * overflow, see {@see TruncatedReadHandle}.
 *
 * @api
 */
final class BoundedReadHandle implements ReadHandleInterface, CloseHandleInterface
{
    use ReadHandleConvenienceMethodsTrait;

    private bool $closed = false;
    private int $bytesRead = 0;
    private bool $limitReached = false;

    /**
     * Takes ownership of `$handle` if it implements {@see CloseHandleInterface}: {@see close()} closes it.
     * Otherwise it is only borrowed.
     *
     * @param ReadHandleInterface $handle The underlying handle to read from.
     * @param int $limit The maximum total number of bytes allowed. If the underlying
     *  handle contains more data, an {@see Exception\RuntimeException} is thrown.
     */
    public function __construct(
        private readonly ReadHandleInterface $handle,
        private readonly int $limit,
    ) {}

    /**
     * Whether the limit has been reached cleanly or the underlying handle has been exhausted.
     *
     * Returns {@see true} when the limit was reached and no overflow was detected,
     * or when the underlying handle itself reports EOF. Returns {@see false} otherwise.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        $this->assertHandleIsOpen();

        return $this->limitReached || $this->handle->reachedEndOfDataSource();
    }

    /**
     * Return data immediately available from the underlying handle, capped to the remaining allowance.
     *
     * If the byte limit has been reached, peeks one available byte to check for
     * overflow: if the underlying handle has more data, throws
     * {@see Exception\RuntimeException}. Otherwise, returns an empty string; this
     * is EOF only once the underlying handle reports EOF.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for the remaining allowance.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If the underlying handle contains more data than the limit allows.
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->assertHandleIsOpen();

        if ($this->bytesRead >= $this->limit) {
            return $this->handleLimitReached(false, new NullCancellationToken());
        }

        $remaining = $this->limit - $this->bytesRead;

        /** @var positive-int $cappedMax */
        $cappedMax = $maxBytes === null ? $remaining : min($maxBytes, $remaining);

        $data = $this->handle->tryRead($cappedMax);
        $this->bytesRead += strlen($data);

        return $data;
    }

    /**
     * Read from the underlying handle, capped to the remaining byte allowance.
     *
     * If the byte limit has been reached, waits for one more byte to check for
     * overflow: if the underlying handle delivers data, throws
     * {@see Exception\RuntimeException}. Otherwise, returns an empty string once
     * the underlying handle reports EOF.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for the remaining allowance.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If the underlying handle contains more data than the limit allows.
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        if ($this->bytesRead >= $this->limit) {
            return $this->handleLimitReached(true, $cancellation);
        }

        $remaining = $this->limit - $this->bytesRead;

        /** @var positive-int $cappedMax */
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
        if (!$this->closed) {
            $this->closed = true;

            if ($this->handle instanceof CloseHandleInterface) {
                $this->handle->close();
            }
        }
    }

    /**
     * Decide between a clean limit and an overflow once $limit bytes were returned.
     *
     * If the underlying handle reports EOF, the data fit exactly. Otherwise one
     * byte is peeked: a blocking peek waits for it, a non-blocking peek only
     * looks at data that is already available. A peeked byte means the data
     * exceeds the limit. An empty peek reports EOF only when the underlying
     * handle now reports EOF; bytes that have not arrived yet are not EOF.
     *
     * @throws Exception\RuntimeException If the underlying handle has overflow data.
     */
    private function handleLimitReached(bool $blocking, CancellationTokenInterface $cancellation): string
    {
        if ($this->limitReached || $this->handle->reachedEndOfDataSource()) {
            $this->limitReached = true;

            return '';
        }

        $peek = $blocking ? $this->handle->read(1, $cancellation) : $this->handle->tryRead(1);
        if ($peek !== '') {
            throw new Exception\RuntimeException(
                'Response body exceeded the configured limit of ' . $this->limit . ' bytes.',
            );
        }

        if ($this->handle->reachedEndOfDataSource()) {
            $this->limitReached = true;
        }

        return '';
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
