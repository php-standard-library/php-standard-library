<?php

declare(strict_types=1);

namespace Psl\Encoding\Base64;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Exception;
use Psl\IO;

use function strlen;

/**
 * A write handle that accepts base64-encoded bytes, buffers until 4-byte groups are available,
 * decodes and writes to the inner handle.
 *
 * Once {@see flush()} has been called, the bytes written to the inner handle, and the inputs that
 * are rejected, are identical to {@see decode()} applied to all the data written with the same
 * variant and padding: in particular, only {@see Variant::Mime} ignores whitespace (CR, LF, space and tab).
 *
 * @api
 */
final class DecodingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private readonly Internal\StreamDecoder $decoder;

    public function __construct(
        private readonly IO\WriteHandleInterface $handle,
        Variant $variant = Variant::Standard,
        bool $padding = true,
    ) {
        $this->decoder = new Internal\StreamDecoder($variant, $padding);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\RangeException If the written bytes are not valid base64.
     * @throws Exception\IncorrectPaddingException If the written bytes have an incorrect padding.
     */
    public function tryWrite(string $bytes): int
    {
        $decoded = $this->decoder->update($bytes);
        if ($decoded !== '') {
            $this->handle->writeAll($decoded);
        }

        return strlen($bytes);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\RangeException If the written bytes are not valid base64.
     * @throws Exception\IncorrectPaddingException If the written bytes have an incorrect padding.
     */
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    /**
     * Flush any remaining buffered base64 data through the decoder to the inner handle.
     *
     * Call this once all data has been written; data written afterwards starts a new encoded stream.
     *
     * @throws Exception\RangeException If the remaining bytes do not form valid base64.
     * @throws Exception\IncorrectPaddingException If the remaining bytes have an incorrect padding.
     */
    public function flush(): void
    {
        $decoded = $this->decoder->finalize();
        if ($decoded !== '') {
            $this->handle->writeAll($decoded);
        }
    }
}
