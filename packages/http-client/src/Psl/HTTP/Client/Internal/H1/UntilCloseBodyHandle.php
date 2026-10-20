<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H1;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

/**
 * Reads the response body until the connection is closed (RFC 9112 Section 7.2).
 *
 * Used as the fallback framing strategy when neither Content-Length nor
 * Transfer-Encoding: chunked is present. The body ends when the underlying
 * stream reaches EOF. I/O errors propagate to the caller.
 *
 * Connections using this framing cannot be reused (no keep-alive), because
 * there is no way to determine the body boundary without closing the stream.
 *
 * Read contract:
 * - {@see read()} returns data until the connection closes.
 * - After EOF, {@see read()} returns '' and {@see reachedEndOfDataSource()} returns true.
 * - I/O errors propagate to the caller.
 *
 * @internal
 *
 * @see ResponseReader::buildBody() Creates this handle as a fallback.
 */
final class UntilCloseBodyHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private bool $eof = false;

    /**
     * @param IO\Reader $reader Buffered reader wrapping the connection stream.
     */
    public function __construct(
        private readonly IO\Reader $reader,
    ) {}

    /**
     * @inheritDoc
     */
    public function tryRead(null|int $maxBytes = null): string
    {
        if ($this->eof) {
            return '';
        }

        $data = $this->reader->tryRead($maxBytes);
        if ($data === '' && $this->reader->reachedEndOfDataSource()) {
            $this->eof = true;
        }

        return $data;
    }

    /**
     * Read body data from the wire until the connection closes.
     *
     * I/O errors remain distinct from a clean connection close.
     *
     * @inheritDoc
     */
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        if ($this->eof) {
            return '';
        }

        $data = $this->reader->read($maxBytes, $cancellation);

        if ($data === '' && $this->reader->reachedEndOfDataSource()) {
            $this->eof = true;
        }

        return $data;
    }

    /**
     * Whether the connection has closed and all data has been returned.
     */
    public function reachedEndOfDataSource(): bool
    {
        return $this->eof;
    }
}
