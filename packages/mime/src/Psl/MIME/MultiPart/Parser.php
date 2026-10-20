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

use function explode;
use function mb_strtolower;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
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

        if (!self::bufferSkipUntil($handle, $buffer, $this->delimiter, $cancellation)) {
            throw MultiPartException::forMalformedMultipartBody('boundary not found');
        }

        $count = 0;
        while (true) {
            self::bufferFill($handle, $buffer, 2, $cancellation);

            if (str_starts_with($buffer, '--')) {
                break;
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

            $count++;
            if ($this->maxParts > 0 && $count > $this->maxParts) {
                throw MultiPartException::forTooManyParts($this->maxParts);
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

        return $headers;
    }

    /**
     * Remove a single trailing CRLF or LF line ending from the content.
     *
     * Used to strip the line ending that precedes a boundary delimiter,
     * which is not part of the body content per RFC 2046.
     */
    private static function trimTrailingLineEnding(string $content): string
    {
        if (str_ends_with($content, "\r\n")) {
            /** @var non-negative-int $len */
            $len = strlen($content) - 2;
            return substr($content, 0, $len);
        }

        if (str_ends_with($content, "\n")) {
            /** @var non-negative-int $len */
            $len = strlen($content) - 1;
            return substr($content, 0, $len);
        }

        return $content;
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
     * Discard input up to and including the needle, reading more chunks as needed.
     *
     * Only the last strlen($needle) - 1 bytes are kept between reads, enough to match a needle
     * split across chunks, so skipping input of any size (such as a preamble) uses constant memory.
     *
     * @param non-empty-string $needle
     *
     * @return bool True if the needle was found (and consumed from the buffer), false on EOF.
     */
    private static function bufferSkipUntil(
        IO\ReadHandleInterface $handle,
        string &$buffer,
        string $needle,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): bool {
        $needleLength = strlen($needle);
        while (true) {
            $pos = strpos($buffer, $needle);
            if ($pos !== false) {
                $buffer = substr($buffer, $pos + $needleLength);
                return true;
            }

            $buffer = $needleLength > 1 ? substr($buffer, 1 - $needleLength) : '';

            $chunk = $handle->read(maxBytes: self::CHUNK_SIZE, cancellation: $cancellation);
            if ($chunk === '') {
                if ($handle->reachedEndOfDataSource()) {
                    return false;
                }

                continue;
            }

            $buffer .= $chunk;
        }
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
     * Stream body content to spool until the next boundary delimiter is found.
     *
     * Reads fixed-size chunks, writing safe prefixes directly to spool while
     * keeping a small overlap tail to handle boundaries spanning chunk edges.
     *
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
        $delimLen = strlen($delimiter);
        $tailSize = $delimLen + 2;
        $written = 0;

        while (true) {
            $pos = strpos($buffer, $delimiter);
            if ($pos !== false) {
                $bodyEnd = substr($buffer, 0, $pos);
                $bodyEnd = self::trimTrailingLineEnding($bodyEnd);
                if ($bodyEnd !== '') {
                    $written += strlen($bodyEnd);
                    if ($maxPartSize > 0 && $written > $maxPartSize) {
                        throw MultiPartException::forMalformedMultipartBody(
                            'part body exceeds maximum size of ' . $maxPartSize . ' bytes',
                        );
                    }

                    $spool->writeAll($bodyEnd, $cancellation);
                }

                $buffer = substr($buffer, $pos + $delimLen);

                self::bufferFill($handle, $buffer, 2, $cancellation);
                return str_starts_with($buffer, '--');
            }

            $bufLen = strlen($buffer);
            $safeLen = $bufLen - $tailSize;
            if ($safeLen > 0) {
                $written += $safeLen;
                if ($maxPartSize > 0 && $written > $maxPartSize) {
                    throw MultiPartException::forMalformedMultipartBody(
                        'part body exceeds maximum size of ' . $maxPartSize . ' bytes',
                    );
                }

                /** @var non-negative-int $safeLen */
                $spool->writeAll(substr($buffer, 0, $safeLen), $cancellation);
                $buffer = substr($buffer, $safeLen);
            }

            $chunk = $handle->read(maxBytes: self::CHUNK_SIZE, cancellation: $cancellation);
            if ($chunk === '') {
                if ($handle->reachedEndOfDataSource()) {
                    if ($buffer !== '') {
                        $spool->writeAll($buffer, $cancellation);
                        $buffer = '';
                    }

                    throw MultiPartException::forMalformedMultipartBody('missing closing delimiter');
                }

                continue;
            }

            $buffer .= $chunk;
        }
    }
}
