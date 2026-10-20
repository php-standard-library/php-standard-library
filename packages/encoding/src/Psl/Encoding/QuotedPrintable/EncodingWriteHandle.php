<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
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
        $length = strlen($bytes);
        if ($this->skipLineFeed && $bytes !== '') {
            $this->skipLineFeed = false;
            if ($bytes[0] === "\n") {
                $bytes = substr($bytes, 1);
            }
        }

        if ($bytes !== '') {
            $this->endsWithCarriageReturn = str_ends_with($bytes, "\r");
        }

        $encoded = $this->encoder->update($bytes);
        if ($encoded !== '') {
            $this->handle->writeAll($encoded);
        }

        return $length;
    }

    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    /**
     * Encode the last line and write it to the inner handle.
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

        $encoded = $this->encoder->finalize();
        if ($encoded !== '') {
            $this->handle->writeAll($encoded);
        }
    }
}
