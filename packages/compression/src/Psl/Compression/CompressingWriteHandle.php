<?php

declare(strict_types=1);

namespace Psl\Compression;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function sprintf;
use function strlen;
use function substr;

/**
 * Compressing write decorator.
 *
 * Accepts raw data via write(), compresses it via the given compressor,
 * and writes the compressed output to the inner handle.
 *
 * Compressed output that cannot be written immediately is buffered internally.
 * Call {@see flush()} to finalize the compression stream and drain all
 * remaining buffered data.
 *
 * @api
 */
final class CompressingWriteHandle implements IO\BufferedWriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    /**
     * The largest part of the pending output copied for one write to the inner handle.
     */
    private const int MAXIMUM_WRITE_CHUNK_SIZE = 65_536;

    /**
     * The output that is not written yet is the part of `$buffer` that starts at `$offset`.
     *
     * Draining advances the offset instead of copying the rest of the buffer: copying it after every write made
     * draining N bytes cost O(N²) when the inner handle accepts a few kilobytes at a time.
     */
    private string $buffer = '';

    /**
     * @var int<0, max>
     */
    private int $offset = 0;

    /**
     * Whether {@see flush()} already finished the compressor's stream, and only has to drain its output.
     *
     * A flush that fails while draining keeps that state, so that its retry neither finishes the stream again
     * (which would add another trailer) nor writes again what the failed attempt already wrote.
     */
    private bool $finished = false;

    public function __construct(
        private readonly IO\WriteHandleInterface $inner,
        private readonly CompressorInterface $compressor,
    ) {}

    /**
     * Try to compress and write data immediately, without waiting.
     *
     * All input bytes are consumed by the compressor and buffered internally.
     * As much compressed output as possible is drained to the inner handle
     * without blocking.
     *
     * @return int<0, max> The number of input bytes consumed (always strlen($bytes)).
     *
     * @throws Exception\RuntimeException If compression fails.
     * @throws IO\Exception\AlreadyClosedException If the inner handle has been closed.
     * @throws IO\Exception\RuntimeException If the write to the inner handle fails.
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        $this->push($bytes);
        $this->drainTry();

        return strlen($bytes);
    }

    /**
     * Compress and write data, waiting if necessary.
     *
     * All input bytes are consumed by the compressor. The compressed output
     * is written to the inner handle, waiting for the handle to become writable
     * if necessary. A single write is performed, which may not drain the
     * entire buffer.
     *
     * @return int<0, max> The number of input bytes consumed (always strlen($bytes)).
     *
     * @throws Exception\RuntimeException If compression fails.
     * @throws IO\Exception\AlreadyClosedException If the inner handle has been closed.
     * @throws IO\Exception\RuntimeException If the write to the inner handle fails.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $this->push($bytes);
        $this->drain($cancellation);

        return strlen($bytes);
    }

    /**
     * Finalize the compression stream and drain all remaining buffered data.
     *
     * Calls {@see CompressorInterface::finish()} to produce the final compressed
     * output, then writes all buffered data to the inner handle.
     *
     * If draining fails, the finished stream and the output the inner handle
     * has not reported accepting are kept: calling flush() again writes that
     * output, without finishing the stream a second time. That output includes
     * whatever the failed write wrote without reporting it: a write to a PSL
     * pipe or socket handle that is cancelled, or fails, after part of its
     * bytes went out reports none of them, so a flush retried after a timeout
     * on such a handle writes those bytes again.
     *
     * @throws Exception\RuntimeException If compression finalization fails.
     * @throws IO\Exception\AlreadyClosedException If the inner handle has been closed.
     * @throws IO\Exception\RuntimeException If the write to the inner handle fails.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    #[Override]
    public function flush(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        if (!$this->finished) {
            $this->buffer .= $this->compressor->finish();
            $this->finished = true;
        }

        $this->drainAll($cancellation);
        $this->finished = false;
    }

    /**
     * Pass `$bytes` to the compressor and keep its output.
     */
    private function push(string $bytes): void
    {
        $output = $this->compressor->push($bytes);
        if ('' !== $bytes || '' !== $output) {
            // The compressor started a new stream, which the next flush must finish.
            $this->finished = false;
        }

        $this->buffer .= $output;
    }

    /**
     * Try to write as much of the buffer as possible without blocking.
     */
    private function drainTry(): void
    {
        if ($this->buffer === '') {
            return;
        }

        $this->advance($this->inner->tryWrite($this->pending()));
    }

    /**
     * Write some of the buffer, waiting if necessary.
     */
    private function drain(CancellationTokenInterface $cancellation): void
    {
        if ($this->buffer === '') {
            return;
        }

        $this->advance($this->inner->write($this->pending(), $cancellation));
    }

    /**
     * Write all the buffer, waiting as long as necessary.
     */
    private function drainAll(CancellationTokenInterface $cancellation): void
    {
        // One counted write at a time, so that what the inner handle accepted stays known if a write fails.
        while ($this->buffer !== '') {
            $written = $this->inner->write($this->pending(), $cancellation);
            if (0 === $written) {
                throw new IO\Exception\RuntimeException(sprintf(
                    'The inner handle accepted no data; %d bytes of output are still pending.',
                    strlen($this->buffer) - $this->offset,
                ));
            }

            $this->advance($written);
        }
    }

    /**
     * The pending output to pass to the inner handle: the buffer itself when nothing of it was written yet, which
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
        // part it drops, so all of them together copy at most as many bytes as were written, and a handle that
        // never fully drains does not keep everything it ever wrote.
        if ($this->offset >= ($length - $this->offset)) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }
}
