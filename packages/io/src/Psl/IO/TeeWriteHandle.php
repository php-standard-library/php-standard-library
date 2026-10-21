<?php

declare(strict_types=1);

namespace Psl\IO;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;

use function sprintf;
use function strlen;
use function substr;

/**
 * A write handle that duplicates all writes to two underlying write handles.
 *
 * Inspired by the Unix `tee` command, this handle forwards every byte written
 * to both {@see $first} and {@see $second}. The caller sees a single
 * {@see WriteHandleInterface} while data is transparently replicated.
 *
 * Because the two underlying handles may accept data at different rates,
 * {@see TeeWriteHandle} maintains an internal pending buffer for bytes that
 * {@see $first} has accepted but {@see $second} has not yet consumed. This
 * provides backpressure: {@see tryWrite()} returns 0 when the pending buffer
 * is non-empty and {@see $second} cannot drain it, preventing the caller from
 * writing more data until both handles are in sync.
 *
 * Writes made concurrently from several fibers never lose or repeat bytes,
 * but they are not serialized: when {@see $second} takes the bytes of one
 * write in several parts, the bytes of another write can come between them,
 * and the two handles then hold the bytes in different orders. While a
 * {@see write()} or {@see flush()} waits, {@see tryWrite()} returns 0.
 *
 * @api
 */
final class TeeWriteHandle implements BufferedWriteHandleInterface, CloseHandleInterface
{
    use WriteHandleConvenienceMethodsTrait;

    /**
     * The largest part of the pending bytes copied for one write to the second handle.
     */
    private const int MAXIMUM_WRITE_CHUNK_SIZE = 65_536;

    /**
     * Bytes accepted by the first handle that are not written to the second, and that no call is writing to it:
     * the part of this string that starts at {@see $pendingOffset}.
     *
     * Draining advances the offset instead of copying the rest of the string: copying it after every write made
     * draining N bytes cost O(N²) when the second handle accepts a few kilobytes at a time.
     */
    private string $pendingForSecond = '';

    /**
     * @var int<0, max>
     */
    private int $pendingOffset = 0;

    /**
     * The number of {@see write()} and {@see flush()} calls in progress.
     *
     * While one of them waits, {@see tryWrite()} writes nothing: its bytes would reach the second handle ahead of
     * bytes that call has not written yet.
     */
    private int $writing = 0;

    private bool $closed = false;

    /**
     * Takes ownership of each handle that implements {@see CloseHandleInterface}: {@see close()} closes it. A
     * handle that does not is only borrowed.
     *
     * @param WriteHandleInterface $first The primary write handle.
     * @param WriteHandleInterface $second The secondary write handle that receives a copy of all data.
     */
    public function __construct(
        private readonly WriteHandleInterface $first,
        private readonly WriteHandleInterface $second,
    ) {}

    /**
     * Try to write bytes to both handles without blocking.
     *
     * The method first attempts to drain any pending bytes (previously accepted
     * by {@see $first} but not yet by {@see $second}) to the second handle. If
     * the pending buffer cannot be fully drained, returns 0 as backpressure to
     * signal the caller should retry later.
     *
     * When the pending buffer is empty, the method writes to {@see $first},
     * then writes the same accepted bytes to {@see $second}. If {@see $second}
     * accepts fewer bytes than {@see $first}, the remainder is stored in the
     * pending buffer for future draining.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     *
     * @return int<0, max> The number of new bytes accepted, or 0 if backpressure is active.
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        $this->assertHandleIsOpen();

        if (0 !== $this->writing) {
            return 0;
        }

        if ($this->pendingForSecond !== '') {
            $this->advancePending($this->second->tryWrite($this->pendingSlice()));

            if ($this->pendingForSecond !== '') {
                return 0;
            }
        }

        $firstN = $this->first->tryWrite($bytes);
        if ($firstN === 0) {
            return 0;
        }

        // Record what the second handle owes before writing to it: if that write fails, the bytes the first
        // handle accepted stay pending.
        $this->pendingForSecond = $firstN === strlen($bytes) ? $bytes : substr($bytes, 0, $firstN);
        $this->pendingOffset = 0;
        $this->advancePending($this->second->tryWrite($this->pendingForSecond));

        return $firstN;
    }

    /**
     * Write bytes to both handles, waiting if necessary.
     *
     * First drains any pending buffer to {@see $second} (waiting until it is
     * empty, see {@see flush()}). Then writes to {@see $first} and, if any bytes
     * were accepted, writes the same bytes to {@see $second}, waiting until it
     * has accepted all of them, to keep both handles in sync.
     *
     * If writing to {@see $second} fails, the bytes {@see $first} accepted and
     * {@see $second} has not reported accepting stay pending, and the next write
     * or flush delivers them first.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled.
     *
     * @return int<0, max> The number of bytes written to the first handle.
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $this->assertHandleIsOpen();

        $this->writing++;

        try {
            $this->drainPending($cancellation);
            $firstN = $this->first->write($bytes, $cancellation);
            if ($firstN > 0) {
                $this->writeToSecond($firstN === strlen($bytes) ? $bytes : substr($bytes, 0, $firstN), $cancellation);
            }

            return $firstN;
        } finally {
            $this->writing--;
        }
    }

    /**
     * Write the pending bytes to {@see $second}, waiting until it has accepted all of them.
     *
     * The pending bytes are written with counted writes: if one fails, the bytes {@see $second} accepted before
     * it are not pending anymore, and a retry writes only the rest. That rest includes whatever the failed write
     * wrote without reporting it: a write to a PSL pipe or socket handle that is cancelled, or fails, after part of
     * its bytes went out reports none of them, so a flush retried after a timeout on such a handle writes those
     * bytes again.
     *
     * @throws Exception\AlreadyClosedException If the handle has been closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled.
     */
    #[Override]
    public function flush(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        $this->assertHandleIsOpen();

        $this->writing++;

        try {
            $this->drainPending($cancellation);
        } finally {
            $this->writing--;
        }
    }

    /**
     * Whether the handle has been closed.
     *
     * @throws void This method never throws.
     */
    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close the handle, closing both underlying handles and clearing the pending buffer.
     *
     * If either underlying handle implements {@see CloseHandleInterface}, it
     * will be closed: both are closed even if closing the first one fails, and
     * a handle passed as both is closed once; the first failure is then
     * rethrown. The pending buffer is discarded. Closing the handle again does
     * nothing. After closing, all operations will throw
     * {@see Exception\AlreadyClosedException}.
     *
     * @throws Exception\RuntimeException If an error occurred while closing one of the handles.
     */
    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->pendingForSecond = '';
        $this->pendingOffset = 0;

        Internal\close_all($this->first, $this->second);
    }

    /**
     * Write the pending bytes to {@see $second}, waiting until it has accepted all of them.
     *
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled.
     */
    private function drainPending(CancellationTokenInterface $cancellation): void
    {
        if ($this->pendingForSecond === '') {
            return;
        }

        $bytes = 0 === $this->pendingOffset
            ? $this->pendingForSecond
            : substr($this->pendingForSecond, $this->pendingOffset);
        $this->pendingForSecond = '';
        $this->pendingOffset = 0;

        $this->writeToSecond($bytes, $cancellation);
    }

    /**
     * Write `$bytes`, which are not pending, to {@see $second}, waiting until it has accepted all of them.
     *
     * No other call sees these bytes while this one writes them, so none writes them again or replaces them.
     * They are written with counted writes; if one fails, the bytes {@see $second} has not reported accepting
     * become pending again, ahead of any bytes that became pending since, and the next write or flush delivers
     * them first.
     *
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws Async\Exception\CancelledException If the cancellation token is cancelled.
     */
    private function writeToSecond(string $bytes, CancellationTokenInterface $cancellation): void
    {
        $length = strlen($bytes);
        $offset = 0;

        try {
            while ($offset < $length) {
                $written = $this->second->write(
                    0 === $offset ? $bytes : substr($bytes, $offset, self::MAXIMUM_WRITE_CHUNK_SIZE),
                    $cancellation,
                );
                if (0 === $written) {
                    throw new Exception\RuntimeException(sprintf(
                        'The second handle accepted no data; %d bytes are still pending.',
                        $length - $offset + strlen($this->pendingForSecond) - $this->pendingOffset,
                    ));
                }

                $offset += $written;
            }
        } finally {
            if ($offset < $length && !$this->closed) {
                $this->pendingForSecond =
                    substr($bytes, $offset) . substr($this->pendingForSecond, $this->pendingOffset);
                $this->pendingOffset = 0;
            }
        }
    }

    /**
     * The pending bytes to pass to the second handle: the pending string itself when none of it was written yet,
     * which copies nothing, and otherwise at most {@see MAXIMUM_WRITE_CHUNK_SIZE} bytes of it.
     */
    private function pendingSlice(): string
    {
        if (0 === $this->pendingOffset) {
            return $this->pendingForSecond;
        }

        return substr($this->pendingForSecond, $this->pendingOffset, self::MAXIMUM_WRITE_CHUNK_SIZE);
    }

    /**
     * Mark `$written` more pending bytes as written to the second handle.
     *
     * @param int<0, max> $written
     */
    private function advancePending(int $written): void
    {
        $this->pendingOffset += $written;
        $length = strlen($this->pendingForSecond);
        if ($this->pendingOffset >= $length) {
            $this->pendingForSecond = '';
            $this->pendingOffset = 0;

            return;
        }

        // Drop the written part once it is at least as large as the rest. That copy is never larger than the
        // part it drops, so all of them together copy at most as many bytes as were written.
        if ($this->pendingOffset >= ($length - $this->pendingOffset)) {
            $this->pendingForSecond = substr($this->pendingForSecond, $this->pendingOffset);
            $this->pendingOffset = 0;
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
}
