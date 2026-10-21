<?php

declare(strict_types=1);

namespace Psl\Encoding\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\IO;

use function sprintf;
use function strlen;
use function substr;

/**
 * Output of an encoding write handle that its inner handle has not accepted yet.
 *
 * The output keeps its order: whatever is queued is written after everything queued before it. The pending part is
 * kept as a string and an offset, so that writing N bytes in many small pieces copies O(N) bytes.
 *
 * A waiting write takes the output it writes out of the queue first, so that a write made while it waits never
 * writes that output again.
 *
 * @internal
 */
final class PendingOutput
{
    /**
     * The largest part of the pending output copied for one write to the inner handle.
     */
    private const int MAXIMUM_WRITE_CHUNK_SIZE = 65_536;

    /**
     * Output that no call is writing: the part of this string that starts at {@see $offset}.
     */
    private string $buffer = '';

    /**
     * @var int<0, max>
     */
    private int $offset = 0;

    /**
     * The number of {@see writeAll()} calls in progress.
     *
     * While one of them waits, {@see tryWrite()} only queues its output: writing it would put it on the inner handle
     * ahead of output that call has not written yet.
     */
    private int $writing = 0;

    public function __construct(
        private readonly IO\WriteHandleInterface $handle,
    ) {}

    /**
     * Queue `$output`, then write as much of the pending output as the inner handle accepts without waiting.
     *
     * @throws IO\Exception\AlreadyClosedException If the inner handle has been closed.
     * @throws IO\Exception\RuntimeException If the write to the inner handle fails.
     */
    public function tryWrite(string $output): void
    {
        $this->buffer .= $output;
        if (0 !== $this->writing) {
            return;
        }

        while ($this->buffer !== '') {
            $written = $this->handle->tryWrite($this->pending());
            if (0 === $written) {
                return;
            }

            $this->advance($written);
        }
    }

    /**
     * Queue `$output`, then write all the pending output, waiting as long as necessary.
     *
     * The output is taken out of the queue before any of it is written: a call made while this one waits does not
     * see it, so does not write it again. The writes are counted: if one fails, the output the inner handle has not
     * reported accepting is queued again, ahead of any output queued since.
     *
     * @throws IO\Exception\AlreadyClosedException If the inner handle has been closed.
     * @throws IO\Exception\RuntimeException If the write to the inner handle fails.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function writeAll(string $output, CancellationTokenInterface $cancellation): void
    {
        $bytes = $this->take() . $output;
        $length = strlen($bytes);
        $offset = 0;
        $this->writing++;

        try {
            while ($offset < $length) {
                $written = $this->handle->write(
                    0 === $offset ? $bytes : substr($bytes, $offset, self::MAXIMUM_WRITE_CHUNK_SIZE),
                    $cancellation,
                );
                if (0 === $written) {
                    throw new IO\Exception\RuntimeException(sprintf(
                        'The inner handle accepted no data; %d bytes of output are still pending.',
                        $length - $offset + strlen($this->buffer) - $this->offset,
                    ));
                }

                $offset += $written;
            }
        } finally {
            $this->writing--;
            if ($offset < $length) {
                $this->buffer = substr($bytes, $offset) . $this->take();
            }
        }
    }

    /**
     * Take the queued output out of the queue.
     */
    private function take(): string
    {
        $output = 0 === $this->offset ? $this->buffer : substr($this->buffer, $this->offset);
        $this->buffer = '';
        $this->offset = 0;

        return $output;
    }

    /**
     * The pending output to pass to the inner handle: the buffer itself when none of it was written yet, which
     * copies nothing, and otherwise at most {@see MAXIMUM_WRITE_CHUNK_SIZE} bytes of it.
     */
    private function pending(): string
    {
        if (0 === $this->offset) {
            return $this->buffer;
        }

        return substr($this->buffer, $this->offset, self::MAXIMUM_WRITE_CHUNK_SIZE);
    }

    /**
     * Mark `$written` more bytes of the pending output as written.
     *
     * @param int<0, max> $written
     */
    private function advance(int $written): void
    {
        $this->offset += $written;
        $length = strlen($this->buffer);
        if ($this->offset >= $length) {
            $this->buffer = '';
            $this->offset = 0;

            return;
        }

        // Drop the written part once it is at least as large as the rest. That copy is never larger than the
        // part it drops, so all of them together copy at most as many bytes as were written.
        if ($this->offset >= ($length - $this->offset)) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }
}
