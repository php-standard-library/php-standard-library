<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Internal\PendingOutput;
use Psl\IO;

use function strlen;

/**
 * A write handle that accepts quoted-printable encoded bytes and writes decoded output to an inner handle.
 *
 * Buffers input until complete lines are available, then decodes them. Once {@see flush()} has been
 * called, the bytes written to the inner handle are identical to {@see decode()} applied to all the
 * data written: soft line breaks are removed and hard line breaks are kept as they appear in the input.
 *
 * @api
 */
final class DecodingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private readonly Internal\StreamDecoder $decoder;

    private readonly PendingOutput $output;

    public function __construct(IO\WriteHandleInterface $handle)
    {
        $this->decoder = new Internal\StreamDecoder();
        $this->output = new PendingOutput($handle);
    }

    /**
     * {@inheritDoc}
     *
     * Accepts all of `$bytes` without waiting: the output the inner handle does not accept right away, or all of
     * it while a {@see write()} waits, stays pending, in order, until a later write delivers it; call
     * {@see write()}, with an empty string if there is nothing more to write, to wait until it is delivered.
     */
    public function tryWrite(string $bytes): int
    {
        $this->output->tryWrite($this->decoder->update($bytes));

        return strlen($bytes);
    }

    /**
     * {@inheritDoc}
     *
     * Accepts all of `$bytes` and writes the output that is ready, together with any output earlier calls to
     * {@see tryWrite()} left pending, waiting until the inner handle has accepted all of it.
     *
     * That pending output is written before `$bytes` is used. If the wait is cancelled after that, `$bytes` counts
     * as written: the part of its output the inner handle has not reported accepting stays pending and the next
     * write delivers it first, so do not write it again. A write to a PSL pipe or socket handle that is cancelled
     * after part of its bytes went out reports none of them, so the next write delivers those bytes again.
     *
     * Calls made concurrently from several fibers never lose or repeat output, but when the inner handle takes the
     * output of one call in several parts, the output of another call can come between them.
     */
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $this->output->writeAll('', $cancellation);
        $this->output->writeAll($this->decoder->update($bytes), $cancellation);

        return strlen($bytes);
    }

    /**
     * Decode any remaining buffered data and write it to the inner handle.
     *
     * Output that earlier writes left pending is written first.
     *
     * Call this once all data has been written.
     */
    public function flush(): void
    {
        $cancellation = new NullCancellationToken();
        $this->output->writeAll('', $cancellation);
        $this->output->writeAll($this->decoder->finalize(), $cancellation);
    }
}
