<?php

declare(strict_types=1);

namespace Psl\Encoding\Base64;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function strlen;

/**
 * A write handle that accepts raw binary data, base64-encodes it, and writes it to the inner handle.
 *
 * Once {@see flush()} has been called, the bytes written to the inner handle are identical to
 * {@see encode()} applied to all the data written, with the same variant and padding: raw bytes are
 * buffered until complete 3-byte groups are available (or complete {@see CHUNK_SIZE} byte lines for
 * {@see Variant::Mime}, which is the only variant that emits {@see LINE_ENDING}, between lines).
 *
 * @api
 */
final class EncodingWriteHandle implements IO\WriteHandleInterface
{
    use IO\WriteHandleConvenienceMethodsTrait;

    private readonly Internal\StreamEncoder $encoder;

    public function __construct(
        private readonly IO\WriteHandleInterface $handle,
        Variant $variant = Variant::Standard,
        bool $padding = true,
    ) {
        $this->encoder = new Internal\StreamEncoder($variant, $padding);
    }

    /**
     * {@inheritDoc}
     */
    public function tryWrite(string $bytes): int
    {
        $encoded = $this->encoder->update($bytes);
        if ($encoded !== '') {
            $this->handle->writeAll($encoded);
        }

        return strlen($bytes);
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    /**
     * Encode any remaining buffered raw data (with padding, if enabled) and write it to the inner handle.
     *
     * Call this once all data has been written; data written afterwards starts a new encoded stream.
     */
    public function flush(): void
    {
        $encoded = $this->encoder->finalize();
        if ($encoded !== '') {
            $this->handle->writeAll($encoded);
        }
    }
}
