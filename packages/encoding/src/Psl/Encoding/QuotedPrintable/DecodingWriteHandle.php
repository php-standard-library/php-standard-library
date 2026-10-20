<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
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

    public function __construct(
        private readonly IO\WriteHandleInterface $handle,
    ) {
        $this->decoder = new Internal\StreamDecoder();
    }

    public function tryWrite(string $bytes): int
    {
        $decoded = $this->decoder->update($bytes);
        if ($decoded !== '') {
            $this->handle->writeAll($decoded);
        }

        return strlen($bytes);
    }

    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    /**
     * Decode any remaining buffered data and write it to the inner handle.
     *
     * Call this once all data has been written.
     */
    public function flush(): void
    {
        $decoded = $this->decoder->finalize();
        if ($decoded !== '') {
            $this->handle->writeAll($decoded);
        }
    }
}
