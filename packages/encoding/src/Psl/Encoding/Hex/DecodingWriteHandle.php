<?php

declare(strict_types=1);

namespace Psl\Encoding\Hex;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Exception;
use Psl\Encoding\Internal\PendingOutput;
use Psl\IO;

use function strlen;
use function substr;

/**
 * A write handle that accepts hex-encoded bytes, buffers until complete 2-byte pairs
 * are available, decodes and writes to the inner handle.
 *
 * @api
 */
final class DecodingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private string $remainder = '';

    private readonly PendingOutput $output;

    public function __construct(IO\WriteHandleInterface $handle)
    {
        $this->output = new PendingOutput($handle);
    }

    /**
     * {@inheritDoc}
     *
     * Accepts all of `$bytes` without waiting: the output the inner handle does not accept right away, or all of
     * it while a {@see write()} waits, stays pending, in order, until a later write delivers it; call
     * {@see write()}, with an empty string if there is nothing more to write, to wait until it is delivered.
     *
     * @throws Exception\RangeException If the written bytes are not valid hex.
     */
    public function tryWrite(string $bytes): int
    {
        $this->output->tryWrite($this->decode($bytes));

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
     *
     * @throws Exception\RangeException If the written bytes are not valid hex.
     */
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $this->output->writeAll('', $cancellation);
        $this->output->writeAll($this->decode($bytes), $cancellation);

        return strlen($bytes);
    }

    /**
     * Decode the complete hex pairs of the buffered input and `$bytes`, and buffer the odd character, if any.
     *
     * @throws Exception\RangeException If the hex data contains invalid characters.
     */
    private function decode(string $bytes): string
    {
        $data = $this->remainder . $bytes;

        $dataLength = strlen($data);
        $usable = $dataLength - ($dataLength % 2);

        if ($usable > 0) {
            $decoded = namespace\decode(substr($data, 0, $usable));
            $this->remainder = substr($data, $usable);

            return $decoded;
        }

        $this->remainder = $data;

        return '';
    }

    /**
     * Flush any remaining buffered hex data through the decoder to the inner handle.
     *
     * Output that earlier writes left pending is written first.
     *
     * @throws Exception\RangeException If there is an odd number of hex characters remaining.
     */
    public function flush(): void
    {
        $cancellation = new NullCancellationToken();
        $this->output->writeAll('', $cancellation);

        if ($this->remainder !== '') {
            // @codeCoverageIgnoreStart
            $decoded = namespace\decode($this->remainder);
            $this->remainder = '';
            $this->output->writeAll($decoded, $cancellation);
            // @codeCoverageIgnoreEnd
        }
    }
}
