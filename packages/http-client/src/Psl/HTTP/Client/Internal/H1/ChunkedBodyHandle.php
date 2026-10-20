<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H1;

use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Internal;
use Psl\HTTP\Message\FieldMap;
use Psl\IO;

use function ctype_xdigit;
use function intval;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Reads a chunked transfer-encoded HTTP/1.x response body (RFC 9112 Section 7.1).
 *
 * Parses chunk sizes, reads chunk data, and handles the terminating zero-length
 * chunk. When the final chunk is reached, any trailing header fields are parsed
 * per RFC 9112 Section 7.1.2 and the trailers {@see Async\Deferred} is resolved.
 *
 * Read contract:
 * - {@see read()} returns decoded body data (without chunk framing) until EOF.
 * - After the final zero-length chunk, {@see read()} returns '' and
 *   {@see reachedEndOfDataSource()} returns true.
 * - Chunk extensions (RFC 9112 Section 7.1.1) are recognized but discarded.
 *
 * @internal
 *
 * @see ResponseReader::buildBody() Creates this handle for chunked responses.
 */
final class ChunkedBodyHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private string $buffer = '';

    /** @var non-negative-int */
    private int $chunkRemaining = 0;

    private bool $completed = false;

    private bool $needsChunkTerminator = false;

    /**
     * @param IO\Reader $reader Buffered reader wrapping the connection stream.
     * @param Async\Deferred<FieldMap> $trailers Resolved with parsed trailing headers when the body is fully consumed.
     */
    public function __construct(
        private readonly IO\Reader $reader,
        private readonly Async\Deferred $trailers,
    ) {}

    /**
     * Return buffered decoded data without blocking or reading from the wire.
     *
     * @inheritDoc
     */
    public function tryRead(null|int $maxBytes = null): string
    {
        if ($this->buffer === '' && !$this->completed) {
            return '';
        }

        return Internal\consume_buffer($this->buffer, $maxBytes);
    }

    /**
     * Read decoded body data, fetching the next chunk from the wire if the buffer is empty.
     *
     * Returns '' once the final zero-length chunk has been consumed.
     *
     * @inheritDoc
     */
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        if ($this->buffer !== '') {
            return Internal\consume_buffer($this->buffer, $maxBytes);
        }

        if ($this->completed) {
            return '';
        }

        $this->fillBuffer($cancellation);

        return Internal\consume_buffer($this->buffer, $maxBytes);
    }

    /**
     * Whether all chunked data has been decoded and consumed.
     *
     * @return bool True after the final zero-length chunk has been processed and all buffered data returned.
     */
    public function reachedEndOfDataSource(): bool
    {
        return $this->completed && $this->buffer === '';
    }

    /**
     * Read the next chunk (or partial chunk) from the wire into the internal buffer.
     *
     * Handles chunk size lines, chunk data, and chunk terminators. When the
     * zero-length terminating chunk is encountered, trailers are parsed and
     * the stream is marked complete.
     *
     * @param CancellationTokenInterface $cancellation Token to cancel the read.
     *
     * @throws ProtocolException If a chunk size line is malformed.
     * @throws IO\Exception\RuntimeException If an I/O error occurs during reading.
     * @throws CancelledException If the cancellation token fires.
     */
    private function fillBuffer(CancellationTokenInterface $cancellation): void
    {
        if ($this->chunkRemaining === 0) {
            if ($this->needsChunkTerminator) {
                $this->reader->readUntil("\r\n", cancellation: $cancellation);
            }

            $sizeLine = $this->reader->readUntil("\r\n", cancellation: $cancellation);
            if ($sizeLine === null) {
                $this->completed = true;
                $this->trailers->complete(FieldMap::default());

                throw ProtocolException::forMalformedResponse(
                    'Connection closed before chunk size was received in chunked transfer encoding.',
                );
            }

            $sizeHex = (($pos = strpos($sizeLine, ';')) !== false ? substr($sizeLine, 0, $pos) : null) ?? $sizeLine;
            // RFC 9112 Section 7.1.1 only allows BWS (SP / HTAB) before ";". SP / HTAB elsewhere around
            // the size is tolerated for leniency; any other byte makes the size malformed.
            $sizeHex = trim($sizeHex, " \t");

            if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
                throw ProtocolException::forMalformedResponse('Invalid chunk size in chunked transfer encoding.');
            }

            /** @var non-negative-int $chunkSize */
            $chunkSize = intval($sizeHex, 16);

            if ($chunkSize === 0) {
                $this->completed = true;
                $this->parseTrailers($cancellation);
                return;
            }

            $this->chunkRemaining = $chunkSize;
            $this->needsChunkTerminator = true;
        }

        $data = $this->reader->read($this->chunkRemaining, $cancellation);

        if ($data === '' && $this->reader->reachedEndOfDataSource()) {
            $this->completed = true;
            $this->trailers->complete(FieldMap::default());

            throw ProtocolException::forMalformedResponse(
                'Connection closed during chunked transfer with '
                . $this->chunkRemaining
                . ' bytes remaining in chunk.',
            );
        }

        /** @var non-negative-int $remaining */
        $remaining = $this->chunkRemaining - strlen($data);
        $this->chunkRemaining = $remaining;
        $this->buffer .= $data;
    }

    /**
     * Parse trailing headers after the final zero-length chunk.
     *
     * Per RFC 9112 Section 7.1.2, trailers consist of header fields terminated
     * by an empty line (CRLF).
     *
     * @throws IO\Exception\RuntimeException If an I/O error occurs.
     * @throws CancelledException
     */
    private function parseTrailers(CancellationTokenInterface $cancellation): void
    {
        /** @var list<list{non-empty-string, string}> $fields */
        $fields = [];

        while (true) {
            $line = $this->reader->readUntil("\r\n", cancellation: $cancellation);
            if ($line === null || $line === '') {
                break;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false || $colonPos === 0) {
                continue;
            }

            /** @var non-empty-string $name */
            $name = substr($line, 0, $colonPos);
            $value = trim(substr($line, $colonPos + 1), " \t");
            $fields[] = [$name, $value];
        }

        $this->trailers->complete(FieldMap::from($fields));
    }
}
