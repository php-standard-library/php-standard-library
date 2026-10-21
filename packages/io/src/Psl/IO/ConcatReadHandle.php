<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

/**
 * A read handle that concatenates two read handles, reading from the first
 * until it reaches EOF, then switching to the second.
 *
 * Each call to {@see read()} delegates to the first handle until it reports
 * EOF, then delegates to the second handle. When both handles are exhausted,
 * the handle reports EOF.
 *
 * {@see tryRead()} follows the same delegation logic but calls tryRead on the
 * underlying handles, returning only immediately available data without waiting.
 *
 * This is useful for composing multiple read sources into a single sequential
 * stream without buffering the entire content in memory.
 *
 * @api
 */
final class ConcatReadHandle implements ReadHandleInterface, CloseHandleInterface
{
    use ReadHandleConvenienceMethodsTrait;

    private bool $closed = false;
    private bool $firstEof = false;

    /**
     * Takes ownership of each handle that implements {@see CloseHandleInterface}: {@see close()} closes it. A
     * handle that does not is only borrowed.
     *
     * @param ReadHandleInterface $first The first handle to read from.
     * @param ReadHandleInterface $second The second handle to read from after $first is exhausted.
     */
    public function __construct(
        private readonly ReadHandleInterface $first,
        private readonly ReadHandleInterface $second,
    ) {}

    /**
     * Whether both underlying handles have been fully consumed.
     *
     * Returns {@see true} only when both the first and second handles have
     * reached their end of data source. Returns {@see false} if either handle
     * still has remaining data.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        $this->assertHandleIsOpen();

        return $this->firstEof && $this->second->reachedEndOfDataSource();
    }

    /**
     * Return immediately available data from the current underlying handle without waiting.
     *
     * Delegates to the first handle's tryRead until the first handle has reached EOF,
     * then delegates to the second handle's tryRead. Returns an empty string when no
     * data is immediately available or both handles are exhausted.
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for all available data.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $this->assertHandleIsOpen();

        if (!$this->firstEof) {
            $data = $this->first->tryRead($maxBytes);
            if ($data !== '') {
                return $data;
            }

            if ($this->first->reachedEndOfDataSource()) {
                $this->firstEof = true;
            } else {
                return '';
            }
        }

        return $this->second->tryRead($maxBytes);
    }

    /**
     * Read the next chunk from the concatenated handles, waiting for data if necessary.
     *
     * Delegates to the first handle until it reaches EOF, then switches to the second
     * handle. Returns an empty string when both handles are exhausted (EOF).
     *
     * @param null|positive-int $maxBytes Maximum number of bytes to return, or null for the entire chunk.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an underlying handle throws during the read.
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->assertHandleIsOpen();

        if (!$this->firstEof) {
            $data = $this->first->read($maxBytes, $cancellation);
            if ($data !== '') {
                return $data;
            }

            if ($this->first->reachedEndOfDataSource()) {
                $this->firstEof = true;
            } else {
                return '';
            }
        }

        return $this->second->read($maxBytes, $cancellation);
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
     * Close the handle and both underlying handles (if they implement {@see CloseHandleInterface}).
     *
     * Both handles are closed even if closing the first one fails, and a handle passed as both is closed once;
     * the first failure is then rethrown. Closing the handle again does nothing.
     *
     * After closing, all read operations will throw {@see Exception\AlreadyClosedException}.
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

        Internal\close_all($this->first, $this->second);
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
