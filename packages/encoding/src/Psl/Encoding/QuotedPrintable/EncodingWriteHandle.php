<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function strlen;

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
     * @param positive-int $maxLineLength Maximum encoded line length (default 76 per RFC 2045).
     * @param non-empty-string $lineEnding Line ending sequence (default "\r\n" per RFC 2045).
     */
    public function __construct(
        private readonly IO\WriteHandleInterface $handle,
        int $maxLineLength = 76,
        string $lineEnding = "\r\n",
    ) {
        $this->encoder = new Internal\StreamEncoder($maxLineLength, $lineEnding);
    }

    public function tryWrite(string $bytes): int
    {
        $encoded = $this->encoder->update($bytes);
        if ($encoded !== '') {
            $this->handle->writeAll($encoded);
        }

        return strlen($bytes);
    }

    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    /**
     * Encode the last line and write it to the inner handle.
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
