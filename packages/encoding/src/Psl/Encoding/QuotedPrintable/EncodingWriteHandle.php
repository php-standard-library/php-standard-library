<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Internal\PendingOutput;
use Psl\IO;

use function str_ends_with;
use function strlen;
use function substr;

/**
 * A write handle that accepts raw text and writes quoted-printable encoded output to an inner handle.
 *
 * Buffers input until complete lines (terminated by CRLF, LF or CR) are available, and encodes
 * each line via {@see encode_line()}. Once {@see flush()} has been called, the bytes written to the
 * inner handle are identical to {@see encode()} applied to all the data written, with the same
 * `$maxLineLength` and `$lineEnding`.
 *
 * @api
 */
final class EncodingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private readonly Internal\StreamEncoder $encoder;

    /**
     * Whether the data written since the last {@see flush()} ends with a CR.
     */
    private bool $endsWithCarriageReturn = false;

    /**
     * Whether {@see flush()} ended the last line at a CR, so that an LF written next completes that CRLF.
     */
    private bool $skipLineFeed = false;

    private readonly PendingOutput $output;

    /**
     * @param positive-int $maxLineLength Maximum encoded line length (default 76 per RFC 2045).
     * @param non-empty-string $lineEnding Line ending sequence (default "\r\n" per RFC 2045).
     */
    public function __construct(IO\WriteHandleInterface $handle, int $maxLineLength = 76, string $lineEnding = "\r\n")
    {
        $this->encoder = new Internal\StreamEncoder($maxLineLength, $lineEnding);
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
        $this->output->tryWrite($this->encode($bytes));

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
        $this->output->writeAll($this->encode($bytes), $cancellation);

        return strlen($bytes);
    }

    /**
     * Pass `$bytes` to the encoder and return the output it produced.
     */
    private function encode(string $bytes): string
    {
        if ($this->skipLineFeed && $bytes !== '') {
            $this->skipLineFeed = false;
            if ($bytes[0] === "\n") {
                $bytes = substr($bytes, 1);
            }
        }

        if ($bytes !== '') {
            $this->endsWithCarriageReturn = str_ends_with($bytes, "\r");
        }

        return $this->encoder->update($bytes);
    }

    /**
     * Encode the last line and write it to the inner handle.
     *
     * Output that earlier writes left pending is written first.
     *
     * Call this once all data has been written; data written afterwards starts a new encoded stream.
     * When the data ends with a CR, that CR is written as a line break, and an LF written right after
     * this call completes that CRLF instead of starting another line.
     */
    public function flush(): void
    {
        if ($this->endsWithCarriageReturn) {
            $this->endsWithCarriageReturn = false;
            $this->skipLineFeed = true;
        }

        $cancellation = new NullCancellationToken();
        $this->output->writeAll('', $cancellation);
        $this->output->writeAll($this->encoder->finalize(), $cancellation);
    }
}
