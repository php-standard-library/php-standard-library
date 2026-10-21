<?php

declare(strict_types=1);

namespace Psl\Encoding\Hex;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Internal\PendingOutput;
use Psl\IO;

use function strlen;

/**
 * A write handle that accepts raw binary data, hex-encodes it, and writes to the inner handle.
 *
 * No buffering is needed since any chunk of binary data can be independently hex-encoded.
 *
 * @api
 */
final class EncodingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

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
     */
    public function tryWrite(string $bytes): int
    {
        $length = strlen($bytes);
        $this->output->tryWrite($length > 0 ? namespace\encode($bytes) : '');

        return $length;
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

        $length = strlen($bytes);
        $this->output->writeAll($length > 0 ? namespace\encode($bytes) : '', $cancellation);

        return $length;
    }
}
