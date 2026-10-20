<?php

declare(strict_types=1);

namespace Psl\MIME\MultiPart;

use Generator;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding;
use Psl\IO;
use Psl\MIME\Exception\MultiPartException;
use Psl\MIME\Headers;
use Psl\MIME\Part\Part;
use Psl\MIME\Part\PartInterface;
use Psl\MIME\TransferEncoding;

use function array_filter;
use function array_values;
use function explode;
use function is_array;
use function max;
use function mb_strtolower;
use function preg_match;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strpos;
use function strspn;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;

/**
 * Streaming multipart body parser.
 *
 * Parses multipart bodies per RFC 2046 SS5.1. Yields {@see PartInterface} instances
 * lazily as they are read from the input stream. Body content is streamed directly
 * to spool handles (memory to disk) in fixed-size chunks, avoiding out-of-memory
 * conditions for large uploads.
 *
 * Optionally decodes Content-Transfer-Encoding (base64, quoted-printable) on parsed
 * parts when constructed with $decodeTransferEncoding enabled.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2046#section-5.1
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 *
 * @api
 */
final class Parser implements ParserInterface
{
    /**
     * Size in bytes of each chunk read from the input stream.
     */
    private const int CHUNK_SIZE = 8192;

    /**
     * Maximum transport padding (spaces and tabs) accepted after a boundary delimiter.
     *
     * A boundary followed by more whitespace than this is treated as content, so deciding
     * whether a line is a delimiter line never needs to buffer more than this.
     */
    private const int MAX_TRANSPORT_PADDING = 1024;

    /**
     * The full boundary delimiter including the leading "--" prefix.
     *
     * @var non-empty-string
     */
    private string $delimiter;

    /**
     * Whether to automatically decode Content-Transfer-Encoding on parsed parts.
     */
    private bool $decodeTransferEncoding;

    /**
     * Maximum bytes per part body kept in memory before spilling to a temporary file on disk.
     *
     * @var int<0, max>
     */
    private int $spoolThreshold;

    /**
     * Maximum number of parts allowed in a single multipart body (0 means unlimited).
     */
    private int $maxParts;

    /**
     * Maximum bytes per individual part body (0 means unlimited).
     *
     * @var int<0, max>
     */
    private int $maxPartSize;

    /**
     * Maximum bytes per part header section (0 means unlimited).
     *
     * @var int<0, max>
     */
    private int $maxHeaderSize;

    /**
     * @param string $boundary The multipart boundary string (1-70 characters per RFC 2046).
     * @param bool $decodeTransferEncoding When true, automatically decode base64 and quoted-printable
     *                                     Content-Transfer-Encoding on parsed parts, stripping the header.
     * @param int<0, max> $spoolThreshold Maximum bytes per part body kept in memory before spilling to disk.
     * @param int<0, max> $maxParts Maximum number of parts allowed (0 = unlimited).
     * @param int<0, max> $maxPartSize Maximum bytes per individual part body (0 = unlimited).
     * @param int<0, max> $maxHeaderSize Maximum bytes per part header section (0 = unlimited): the header
     *                                   fields from the start of the first one up to, but not including,
     *                                   the line break that ends the last one. The rest of the boundary
     *                                   line and the blank line are not counted. Header sections are
     *                                   buffered in memory, so this bounds the memory a single part can
     *                                   claim before its body starts streaming.
     *
     * @throws MultiPartException If the boundary is empty or exceeds 70 characters.
     */
    public function __construct(
        string $boundary,
        bool $decodeTransferEncoding = false,
        int $spoolThreshold = 2_097_152,
        int $maxParts = 0,
        int $maxPartSize = 0,
        int $maxHeaderSize = 16_384,
    ) {
        if ($boundary === '' || strlen($boundary) > 70) {
            throw MultiPartException::forInvalidBoundary($boundary);
        }

        $this->delimiter = '--' . $boundary;
        $this->decodeTransferEncoding = $decodeTransferEncoding;
        $this->spoolThreshold = $spoolThreshold;
        $this->maxParts = $maxParts;
        $this->maxPartSize = $maxPartSize;
        $this->maxHeaderSize = $maxHeaderSize;
    }

    /**
     * Parse a multipart body from a readable stream.
     *
     * Yields Part objects lazily. Each part's body is backed by a spool handle
     * that automatically spills from memory to disk when the threshold is exceeded.
     *
     * @return Generator<int, PartInterface, void, void>
     *
     * @throws MultiPartException If the multipart body is malformed or exceeds limits.
     */
    public function parse(
        IO\ReadHandleInterface $handle,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Generator {
        $buffer = '';

        if (!self::bufferSkipPreamble($handle, $buffer, $this->delimiter, $cancellation)) {
            throw MultiPartException::forMalformedMultipartBody('boundary not found');
        }

        $count = 0;
        while (true) {
            self::bufferFill($handle, $buffer, 2, $cancellation);

            if (str_starts_with($buffer, '--')) {
                break;
            }

            // Another part starts here: enforce the limit before reading any of it.
            $count++;
            if ($this->maxParts > 0 && $count > $this->maxParts) {
                throw MultiPartException::forTooManyParts($this->maxParts);
            }

            $headerSection = self::bufferReadHeaderSection($handle, $buffer, $this->maxHeaderSize, $cancellation);
            if ($headerSection === null) {
                throw MultiPartException::forMalformedMultipartBody('malformed part headers');
            }

            $headers = Headers::fromPairs(self::parseHeaders($headerSection));

            $spool = IO\spool($this->spoolThreshold);
            $isClose = self::streamBodyToSpool(
                $handle,
                $buffer,
                $spool,
                $this->delimiter,
                $this->maxPartSize,
                $cancellation,
            );
            $spool->seek(0);

            $part = new Part($headers, $spool);
            if ($this->decodeTransferEncoding) {
                $part = $this->decodePart($part);
            }

            yield $part;

            if ($isClose) {
                break;
            }
        }
    }

    /**
     * Parse a raw header section string into name-value pairs.
     *
     * Handles header continuation lines (lines starting with whitespace) by
     * appending their content to the preceding header value.
     *
     * @return list<array{string, string}>
     */
    private static function parseHeaders(string $headerSection): array
    {
        if ($headerSection === '') {
            return [];
        }

        $headerSection = str_replace("\r\n", "\n", $headerSection);

        $headers = [];
        $lines = explode("\n", $headerSection);

        $currentName = null;
        $currentValue = '';

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
                $currentValue .= ' ' . trim($line, " \t");
                continue;
            }

            if ($currentName !== null) {
                $headers[] = [$currentName, $currentValue];
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                $currentName = null;
                continue;
            }

            $currentName = trim(substr($line, 0, $colonPos), " \t");
            $currentValue = trim(substr($line, $colonPos + 1), " \t");
        }

        if ($currentName !== null) {
            $headers[] = [$currentName, $currentValue];
        }

        return array_values(array_filter(
            $headers,
            static fn(array $pair): bool => (
                preg_match('/\A[\x21-\x39\x3b-\x7e]+\z/', $pair[0]) === 1
                && strpbrk($pair[1], "\r\n\0") === false
            ),
        ));
    }

    /**
     * Decode the Content-Transfer-Encoding of a parsed part.
     *
     * For base64 and quoted-printable encodings, wraps the part body in the
     * corresponding decoding read handle and removes the Content-Transfer-Encoding
     * header. For 7bit, 8bit, binary, or absent encoding, returns the part unchanged.
     */
    private function decodePart(PartInterface $part): PartInterface
    {
        $encoding = null;
        $filteredHeaders = [];
        foreach ($part->headers->pairs() as [$name, $value]) {
            if (mb_strtolower($name) === 'content-transfer-encoding') {
                $encoding = TransferEncoding::tryFrom(mb_strtolower(trim($value, " \t")));
            } else {
                $filteredHeaders[] = [$name, $value];
            }
        }

        if (
            $encoding === null
            || $encoding === TransferEncoding::SevenBit
            || $encoding === TransferEncoding::EightBit
            || $encoding === TransferEncoding::Binary
        ) {
            return $part;
        }

        return new Part(Headers::fromPairs($filteredHeaders), match ($encoding) {
            TransferEncoding::Base64 => new Encoding\Base64\DecodingReadHandle(
                $part->body(),
                Encoding\Base64\Variant::Mime,
            ),
            TransferEncoding::QuotedPrintable => new Encoding\QuotedPrintable\DecodingReadHandle($part->body()),
        });
    }

    /**
     * Read chunks from handle until buffer has at least $minSize bytes or EOF.
     */
    private static function bufferFill(
        IO\ReadHandleInterface $handle,
        string &$buffer,
        int $minSize,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        while (strlen($buffer) < $minSize) {
            $chunk = $handle->read(maxBytes: self::CHUNK_SIZE, cancellation: $cancellation);
            if ($chunk === '') {
                if ($handle->reachedEndOfDataSource()) {
                    return;
                }

                continue;
            }

            $buffer .= $chunk;
        }
    }

    /**
     * Discard the preamble: all input up to and including the first boundary delimiter.
     *
     * Only the input that may still be part of a delimiter line is kept between reads, so
     * skipping a preamble of any size uses constant memory.
     *
     * @param non-empty-string $delimiter
     *
     * @return bool True if a delimiter was found (and consumed from the buffer), false on EOF.
     */
    private static function bufferSkipPreamble(
        IO\ReadHandleInterface $handle,
        string &$buffer,
        string $delimiter,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): bool {
        // The body starts at the start of a line.
        $lineStart = true;
        $eof = false;
        while (true) {
            $found = self::findDelimiter($buffer, $delimiter, $lineStart, $eof);
            if (is_array($found)) {
                $buffer = substr($buffer, $found[1]);
                return true;
            }

            if ($found > 0) {
                $buffer = substr($buffer, $found);
                $lineStart = false;
            }

            if ($eof) {
                return false;
            }

            $eof = self::bufferRead($handle, $buffer, $cancellation);
        }
    }

    /**
     * Append the next chunk of input to the buffer.
     *
     * @return bool True once the end of the input has been reached.
     */
    private static function bufferRead(
        IO\ReadHandleInterface $handle,
        string &$buffer,
        CancellationTokenInterface $cancellation,
    ): bool {
        $chunk = $handle->read(maxBytes: self::CHUNK_SIZE, cancellation: $cancellation);
        if ($chunk === '') {
            return $handle->reachedEndOfDataSource();
        }

        $buffer .= $chunk;

        return false;
    }

    /**
     * Find the first boundary delimiter line in the buffer.
     *
     * Per RFC 2046 §5.1.1, a delimiter only counts at the start of a line - right after a line
     * feed, or at the start of the buffer when $lineStart is true - and only when it is followed
     * by "--" (the close delimiter) or by optional transport padding and a line break. Any other
     * occurrence of the delimiter (inside a line, or as the prefix of a longer word such as
     * "--boundary-and-more") is content.
     *
     * @param non-empty-string $delimiter
     * @param bool $lineStart Whether the buffer starts at the start of a line.
     * @param bool $eof Whether the end of the input has been reached, so the buffer will not grow.
     *
     * @return array{int<0, max>, int<0, max>}|int<0, max> When found, the offset where the content
     *                                                     before the delimiter ends (the line break
     *                                                     preceding the delimiter belongs to it) and
     *                                                     the offset right after the delimiter.
     *                                                     Otherwise, the number of leading bytes
     *                                                     that are content for certain.
     */
    private static function findDelimiter(string $buffer, string $delimiter, bool $lineStart, bool $eof): array|int
    {
        $delimiterLength = strlen($delimiter);
        $position = $lineStart && str_starts_with($buffer, $delimiter)
            ? 0
            : self::findLineStartingWith($buffer, $delimiter, 0);

        while ($position !== null) {
            // The line break before the delimiter (LF or CRLF) belongs to the delimiter.
            $contentEnd = 0;
            if ($position >= 2 && $buffer[$position - 2] === "\r") {
                $contentEnd = $position - 2;
            } elseif ($position >= 1) {
                $contentEnd = $position - 1;
            }

            $end = $position + $delimiterLength;
            $isDelimiterLine = self::isDelimiterLine($buffer, $end, $eof);
            if ($isDelimiterLine === null) {
                // Undecided until more input arrives: keep the candidate and its line break.
                return $contentEnd;
            }

            if ($isDelimiterLine) {
                return [$contentEnd, $end];
            }

            $position = self::findLineStartingWith($buffer, $delimiter, $position);
        }

        // The tail may still hold the start of a line break and a delimiter.
        return max(0, strlen($buffer) - ($delimiterLength + 2));
    }

    /**
     * Return the offset of the first line, after a line feed at or after $offset, that starts
     * with the given prefix, or null if there is none.
     *
     * @param non-empty-string $prefix
     *
     * @return null|positive-int
     */
    private static function findLineStartingWith(string $buffer, string $prefix, int $offset): null|int
    {
        $lineFeed = strpos($buffer, "\n" . $prefix, $offset);

        return $lineFeed === false ? null : $lineFeed + 1;
    }

    /**
     * Whether the input at $offset, right after a boundary delimiter, completes a delimiter line:
     * "--" (the close delimiter), or transport padding (spaces and tabs) and a line break.
     *
     * @return null|bool Null when the buffer ends before this can be decided.
     */
    private static function isDelimiterLine(string $buffer, int $offset, bool $eof): null|bool
    {
        $length = strlen($buffer);
        if ($offset < $length && $buffer[$offset] === '-') {
            if (($offset + 1) < $length) {
                return $buffer[$offset + 1] === '-';
            }

            return $eof ? false : null;
        }

        $lineBreak = $offset + strspn($buffer, " \t", $offset);
        if (($lineBreak - $offset) > self::MAX_TRANSPORT_PADDING) {
            return false;
        }

        if ($lineBreak < $length && $buffer[$lineBreak] === "\r") {
            $lineBreak++;
        }

        if ($lineBreak >= $length) {
            // A delimiter cut off by the end of the input is still taken as one; the part that
            // should follow it is then reported as malformed.
            return $eof ? true : null;
        }

        return $buffer[$lineBreak] === "\n";
    }

    /**
     * Read a part's header section: everything before the first blank line (CRLF CRLF, LF LF,
     * or a mix of both).
     *
     * The buffer starts right after a boundary delimiter, so its first line is the rest of the
     * boundary line; it is not counted as header data once its line break has been read.
     *
     * Returns the header section with the blank line consumed from the buffer, or null on EOF.
     * At most $maxSize bytes of header data (plus the rest of the boundary line, the blank line
     * and one read chunk) are ever buffered.
     *
     * @param int<0, max> $maxSize Maximum header section size in bytes (0 = unlimited).
     *
     * @throws MultiPartException If the header section exceeds $maxSize bytes.
     */
    private static function bufferReadHeaderSection(
        IO\ReadHandleInterface $handle,
        string &$buffer,
        int $maxSize,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): null|string {
        while (true) {
            $match = [];
            $found = preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1;

            // Without a blank line yet, one could still start in the last 3 buffered bytes.
            $end = $found ? (int) $match[0][1] : strlen($buffer) - 3;
            $boundaryLineEnd = strpos($buffer, "\n");
            $size = $boundaryLineEnd === false ? $end : $end - ($boundaryLineEnd + 1);
            if ($maxSize > 0 && $size > $maxSize) {
                throw MultiPartException::forMalformedMultipartBody(
                    'part header section exceeds maximum size of ' . $maxSize . ' bytes',
                );
            }

            if ($found) {
                /** @var non-negative-int $end */
                $result = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + strlen($match[0][0]));
                return $result;
            }

            $chunk = $handle->read(maxBytes: self::CHUNK_SIZE, cancellation: $cancellation);
            if ($chunk === '') {
                if ($handle->reachedEndOfDataSource()) {
                    return null;
                }

                continue;
            }

            $buffer .= $chunk;
        }
    }

    /**
     * Stream body content to spool until the next boundary delimiter line is found.
     *
     * Reads fixed-size chunks, writing the content that cannot be part of a delimiter line
     * directly to spool while keeping a small tail to handle delimiters spanning chunk edges.
     *
     * @param non-empty-string $delimiter
     * @param int<0, max> $maxPartSize Maximum bytes per part body (0 = unlimited).
     *
     * @return bool True if close delimiter follows (last part), false otherwise.
     */
    private static function streamBodyToSpool(
        IO\ReadHandleInterface $handle,
        string &$buffer,
        IO\CloseSeekReadWriteStreamHandle $spool,
        string $delimiter,
        int $maxPartSize,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): bool {
        $written = 0;
        // The body starts right after the blank line that ends the part headers.
        $lineStart = true;
        $eof = false;

        while (true) {
            $found = self::findDelimiter($buffer, $delimiter, $lineStart, $eof);
            $contentLength = is_array($found) ? $found[0] : $found;
            if ($contentLength > 0) {
                $written += $contentLength;
                if ($maxPartSize > 0 && $written > $maxPartSize) {
                    throw MultiPartException::forMalformedMultipartBody(
                        'part body exceeds maximum size of ' . $maxPartSize . ' bytes',
                    );
                }

                $spool->writeAll(substr($buffer, 0, $contentLength), $cancellation);
            }

            if (is_array($found)) {
                $buffer = substr($buffer, $found[1]);

                self::bufferFill($handle, $buffer, 2, $cancellation);
                return str_starts_with($buffer, '--');
            }

            if ($contentLength > 0) {
                $buffer = substr($buffer, $contentLength);
                $lineStart = false;
            }

            if ($eof) {
                if ($buffer !== '') {
                    $spool->writeAll($buffer, $cancellation);
                    $buffer = '';
                }

                throw MultiPartException::forMalformedMultipartBody('missing closing delimiter');
            }

            $eof = self::bufferRead($handle, $buffer, $cancellation);
        }
    }
}
