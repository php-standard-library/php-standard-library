<?php

declare(strict_types=1);

namespace Psl\IO;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;

use function sprintf;
use function strlen;
use function substr;

/**
 * @require-implements WriteHandleInterface
 *
 * @api
 */
trait WriteHandleConvenienceMethodsTrait
{
    /**
     * Write all of the requested data.
     *
     * A wrapper around `write()` that will:
     * - do multiple writes if necessary to write the entire provided buffer
     * - throws `Exception\RuntimeException` if it is not possible to write all the requested data
     *
     * It is possible for this to never return, e.g. if called on a pipe or
     * or socket which the other end keeps open forever. Set a timeout if you
     * do not want this to happen.
     *
     * @throws Exception\AlreadyClosedException If the handle has been already closed.
     * @throws Exception\RuntimeException If an error occurred during the operation.
     * @throws CancelledException If the cancellation token is cancelled.
     */
    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        if ('' === $bytes) {
            return;
        }

        $length = strlen($bytes);

        // Keep the caller's string and an offset into it: the first write gets the string itself, which copies
        // nothing, and every later write gets a slice of at most 64 KiB. Passing the whole remainder instead
        // copied it on every pass, which made writing N bytes cost O(N²) for a handle that accepts a few
        // kilobytes per call.
        $offset = $this->write($bytes, $cancellation);
        while (0 !== $offset && $offset < $length) {
            $written = $this->write(substr($bytes, $offset, 65_536), $cancellation);
            if (0 === $written) {
                break;
            }

            $offset += $written;
        }

        if ($offset < $length) {
            // @codeCoverageIgnoreStart
            throw new Exception\RuntimeException(sprintf(
                'asked to write %d bytes, but only able to write %d bytes',
                $length,
                $offset,
            ));
            // @codeCoverageIgnoreEnd
        }
    }
}
