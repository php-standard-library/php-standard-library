<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

use function min;
use function strlen;

/**
 * A read handle that reads exactly a fixed number of bytes from an underlying handle.
 *
 * This handle wraps a {@see ReadHandleInterface} and enforces that exactly
 * {@see $length} bytes are consumed. Once the expected number of bytes has been
 * read, the handle reports EOF via {@see reachedEndOfDataSource()}.
 *
 * If the underlying handle reaches EOF before the expected number of bytes has
 * been read, a {@see Exception\RuntimeException} is thrown to signal a premature
 * EOF condition. This guarantees that consumers always receive exactly the
 * declared amount of data, or an explicit error.
 *
 * @api
 */
final class FixedLengthReadHandle implements ReadHandleInterface, CloseHandleInterface
{
    use ReadHandleConvenienceMethodsTrait;

    /**
     * Number of bytes remaining to be read.
     *
     * @var non-negative-int
     */
    private int $remaining;

    /**
     * Total number of bytes expected, stored for error messages.
     *
     * @var non-negative-int
     */
    private readonly int $length;

    private bool $closed = false;

    /**
     * Takes ownership of `$handle` if it implements {@see CloseHandleInterface}: {@see close()} closes it.
     * Otherwise it is only borrowed.
     *
     * @param ReadHandleInterface $handle The underlying handle to read from.
     * @param non-negative-int $length The exact number of bytes expected from the underlying handle.
     */
    public function __construct(
        private readonly ReadHandleInterface $handle,
        int $length,
    ) {
        $this->length = $length;
        $this->remaining = $length;
    }

    /**
     * Whether exactly {@see $length} bytes have been consumed.
     *
     * Returns {@see true} only when all expected bytes have been successfully
     * read from the underlying handle. Returns {@see false} if there are still
     * bytes remaining to be read.
     *
     * Unlike the underlying handle's EOF state, this method does not reflect
     * premature EOF. If the underlying handle reaches EOF early, calling
     * {@see read()} or {@see tryRead()} will throw a {@see Exception\RuntimeException}
     * rather than silently reporting EOF here.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        $this->assertHandleIsOpen();

        return $this->remaining === 0;
    }

    /**
     * Return available data without blocking, limited to the remaining byte count.
     *
     * If all expected bytes have already been consumed, returns an empty string
     * (EOF). Otherwise, delegates to the underlying handle's {@see tryRead()}
     * with at most the remaining byte count.
     *
     * If the underlying handle returns an empty string and has reached its own
     * EOF while bytes are still remaining, a {@see Exception\RuntimeException}
     * is thrown to signal premature EOF. If the underlying handle returns an
     * empty string but has NOT reached EOF (i.e., it is simply not ready to
     * provide data yet), an empty string is returned without error.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for up to the remaining count.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If the underlying handle reaches EOF before all expected bytes are read (premature EOF).
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->assertHandleIsOpen();

        if ($this->remaining === 0) {
            return '';
        }

        $toRead = $maxBytes !== null ? min($maxBytes, $this->remaining) : $this->remaining;
        $data = $this->handle->tryRead($toRead);

        if ($data === '' && $this->handle->reachedEndOfDataSource()) {
            $consumed = $this->length - $this->remaining;

            throw new Exception\RuntimeException(
                'Expected ' . $this->length . ' bytes, but only ' . $consumed . ' were available (premature EOF)',
            );
        }

        /** @var non-negative-int $remaining */
        $remaining = $this->remaining - strlen($data);
        $this->remaining = $remaining;

        return $data;
    }

    /**
     * Read data from the underlying handle, waiting if necessary, limited to the remaining byte count.
     *
     * If all expected bytes have already been consumed, returns an empty string
     * (EOF). Otherwise, delegates to the underlying handle's {@see read()} with
     * at most the remaining byte count.
     *
     * If the underlying handle returns an empty string and has reached its own
     * EOF while bytes are still remaining, a {@see Exception\RuntimeException}
     * is thrown to signal premature EOF. This ensures consumers always receive
     * exactly the declared number of bytes, or an explicit error.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for up to the remaining count.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If the underlying handle reaches EOF before all expected bytes are read (premature EOF).
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled.
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        if ($this->remaining === 0) {
            return '';
        }

        $toRead = $maxBytes !== null ? min($maxBytes, $this->remaining) : $this->remaining;
        $data = $this->handle->read($toRead, $cancellation);

        if ($data === '' && $this->handle->reachedEndOfDataSource()) {
            $consumed = $this->length - $this->remaining;

            throw new Exception\RuntimeException(
                'Expected ' . $this->length . ' bytes, but only ' . $consumed . ' were available (premature EOF)',
            );
        }

        /** @var non-negative-int $remaining */
        $remaining = $this->remaining - strlen($data);
        $this->remaining = $remaining;

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
     * If the underlying handle implements {@see CloseHandleInterface}, it will
     * also be closed. Closing the handle again does nothing. After closing, all
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
