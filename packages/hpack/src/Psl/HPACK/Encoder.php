<?php

declare(strict_types=1);

namespace Psl\HPACK;

use Psl\HPACK\Exception\HeaderListSizeException;
use Psl\HPACK\Exception\InvalidSizeException;
use Psl\HPACK\Internal\DynamicTable;
use Psl\HPACK\Internal\Huffman;
use Psl\HPACK\Internal\IntegerCodec;
use Psl\HPACK\Internal\StaticTable;

use function strlen;

/**
 * HPACK encoder per RFC 7541.
 *
 * Compresses HTTP/2 header fields into a compact binary representation using
 * static table lookups, dynamic table indexing, and Huffman coding.
 *
 * The encoder is stateful - it maintains a dynamic table that evolves across
 * multiple encode() calls within the same HTTP/2 connection. Each connection
 * should use its own Encoder instance.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7541
 *
 * @api
 */
final class Encoder
{
    private DynamicTable $dynamicTable;

    private int $maxHeaderListSize;

    private null|int $pendingMinTableSize = null;

    private null|int $pendingFinalTableSize = null;

    /**
     * Create a new HPACK encoder.
     *
     * @param int<0, max> $maxTableSize Maximum dynamic table size in bytes (default: 4096 per RFC 7541 Section 4.2).
     * @param int<0, max> $maxHeaderListSize Maximum total decompressed header list size in bytes.
     *                                       Each header contributes name length + value length + 32 bytes overhead.
     *
     * @throws InvalidSizeException If $maxTableSize or $maxHeaderListSize is negative.
     */
    public function __construct(int $maxTableSize = 4_096, int $maxHeaderListSize = 16_384)
    {
        // @mago-expect analysis:redundant-comparison,impossible-condition,no-value - runtime guard for untyped callers
        if ($maxTableSize < 0) {
            throw InvalidSizeException::forNegativeTableSize($maxTableSize);
        }

        // @mago-expect analysis:redundant-comparison,impossible-condition,no-value - runtime guard for untyped callers
        if ($maxHeaderListSize < 0) {
            throw InvalidSizeException::forNegativeHeaderListSize($maxHeaderListSize);
        }

        $this->dynamicTable = new DynamicTable($maxTableSize);
        $this->maxHeaderListSize = $maxHeaderListSize;
    }

    /**
     * Update the maximum allowed decompressed header list size.
     *
     * This is an application-level limit, not a protocol-level table size.
     * Headers that exceed this limit during encoding will throw.
     *
     * @param int<0, max> $maxHeaderListSize Maximum total size in bytes.
     *
     * @throws InvalidSizeException If $maxHeaderListSize is negative.
     */
    public function setMaxHeaderListSize(int $maxHeaderListSize): void
    {
        // @mago-expect analysis:redundant-comparison,impossible-condition,no-value - runtime guard for untyped callers
        if ($maxHeaderListSize < 0) {
            throw InvalidSizeException::forNegativeHeaderListSize($maxHeaderListSize);
        }

        $this->maxHeaderListSize = $maxHeaderListSize;
    }

    /**
     * Signal a dynamic table size change.
     *
     * The new size is queued and emitted as a table size update instruction
     * at the start of the next encoded header block, per RFC 7541 Section 4.2.
     *
     * If resize() is called multiple times before the next encode(), the encoder
     * emits the minimum size seen followed by the final size, as required by the RFC.
     *
     * @param int<0, max> $maxSize New maximum dynamic table size in bytes.
     *
     * @throws InvalidSizeException If $maxSize is negative.
     */
    public function resize(int $maxSize): void
    {
        // @mago-expect analysis:redundant-comparison,impossible-condition,no-value - runtime guard for untyped callers
        if ($maxSize < 0) {
            throw InvalidSizeException::forNegativeTableSize($maxSize);
        }

        $this->dynamicTable->setMaxSize($maxSize);

        if ($this->pendingMinTableSize === null || $maxSize < $this->pendingMinTableSize) {
            $this->pendingMinTableSize = $maxSize;
        }

        $this->pendingFinalTableSize = $maxSize;
    }

    /**
     * Encode a set of header fields into an HPACK header block.
     *
     * The encoder selects the most compact representation for each header:
     * indexed, literal with incremental indexing, or literal never indexed
     * (for sensitive headers). Huffman coding is applied when it reduces size.
     *
     * This method updates the dynamic table state. Subsequent calls within the
     * same connection benefit from previously indexed headers.
     *
     * @param iterable<Header> $headers The header fields to encode.
     *
     * @throws HeaderListSizeException If the total decompressed header list size exceeds the configured limit.
     */
    public function encode(iterable $headers): string
    {
        $headers = $this->prepareHeaders($headers);
        $result = $this->encodePendingTableSize();

        foreach ($headers as $header) {
            $nameLen = strlen($header->name);
            $valueLen = strlen($header->value);
            $result .= $this->encodeHeaderEntry($header->name, $nameLen, $header->value, $valueLen, $header->sensitive);
        }

        return $result;
    }

    /**
     * Encode a :status pseudo-header followed by response headers.
     *
     * Convenience method for HTTP/2 response encoding. The :status pseudo-header is prepended automatically
     * and benefits from static table indexing (common status codes like "200",
     * "204", "304", "404", "500" are pre-indexed).
     *
     * @param string $status The HTTP status code as a string (e.g. "200", "404").
     * @param iterable<Header> $headers The response headers to encode after :status.
     *
     * @throws HeaderListSizeException If the total decompressed header list size exceeds the configured limit.
     *
     * @return non-empty-string The encoded header block.
     */
    public function encodeWithStatus(string $status, iterable $headers): string
    {
        $statusLen = strlen($status);
        $headers = $this->prepareHeaders($headers, 7 + $statusLen + 32);
        $result = $this->encodePendingTableSize();
        $result .= $this->encodeHeaderEntry(':status', 7, $status, $statusLen, false);

        foreach ($headers as $header) {
            $nameLen = strlen($header->name);
            $valueLen = strlen($header->value);
            $result .= $this->encodeHeaderEntry($header->name, $nameLen, $header->value, $valueLen, $header->sensitive);
        }

        return $result;
    }

    /**
     * @param iterable<Header> $headers
     *
     * @return list<Header>
     */
    private function prepareHeaders(iterable $headers, int $totalSize = 0): array
    {
        if ($totalSize > $this->maxHeaderListSize) {
            throw HeaderListSizeException::forExceededLimit($totalSize, $this->maxHeaderListSize);
        }

        $result = [];
        foreach ($headers as $header) {
            $totalSize += strlen($header->name) + strlen($header->value) + 32;
            if ($totalSize > $this->maxHeaderListSize) {
                throw HeaderListSizeException::forExceededLimit($totalSize, $this->maxHeaderListSize);
            }

            $result[] = $header;
        }

        return $result;
    }

    private function encodePendingTableSize(): string
    {
        if ($this->pendingFinalTableSize === null) {
            return '';
        }

        $result = '';
        if ($this->pendingMinTableSize !== null && $this->pendingMinTableSize !== $this->pendingFinalTableSize) {
            $result .= IntegerCodec::encode($this->pendingMinTableSize, 5, 0b0010_0000);
        }

        $result .= IntegerCodec::encode($this->pendingFinalTableSize, 5, 0b0010_0000);
        $this->pendingMinTableSize = null;
        $this->pendingFinalTableSize = null;

        return $result;
    }

    /**
     * @param non-empty-lowercase-string $name
     * @param string $value
     * @param bool   $sensitive
     *
     * @return non-empty-string
     */
    private function encodeHeaderEntry(
        string $name,
        int $nameLen,
        string $value,
        int $valueLen,
        bool $sensitive,
    ): string {
        if ($sensitive) {
            return $this->encodeLiteralNeverIndexed($name, $nameLen, $value, $valueLen);
        }

        $staticResult = StaticTable::search($name, $value);

        $nameMatchIndex = null;

        if ($staticResult !== null) {
            [$index, $fullMatch] = $staticResult;
            if ($fullMatch) {
                return IntegerCodec::encode($index, 7, 0b1000_0000);
            }

            $nameMatchIndex = $index;
        }

        $dynamicResult = $this->dynamicTable->search($name, $value);

        if ($dynamicResult !== null) {
            [$dynIndex, $fullMatch] = $dynamicResult;
            $combinedIndex = $dynIndex + 62;
            if ($fullMatch) {
                return IntegerCodec::encode($combinedIndex, 7, 0b1000_0000);
            }

            $nameMatchIndex ??= $combinedIndex;
        }

        $this->dynamicTable->insert($name, $nameLen, $value, $valueLen);
        if ($nameMatchIndex !== null) {
            return IntegerCodec::encode($nameMatchIndex, 6, 0b0100_0000) . $this->encodeString($value, $valueLen);
        }

        return "\x40" . $this->encodeString($name, $nameLen) . $this->encodeString($value, $valueLen);
    }

    /**
     * @param non-empty-lowercase-string $name
     * @param string $value
     *
     * @return non-empty-string
     */
    private function encodeLiteralNeverIndexed(string $name, int $nameLen, string $value, int $valueLen): string
    {
        $staticResult = StaticTable::search($name, $value);
        $nameIndex = 0;

        if ($staticResult !== null) {
            $nameIndex = $staticResult[0];
        } else {
            $dynamicResult = $this->dynamicTable->search($name, $value);
            if ($dynamicResult !== null) {
                $nameIndex = $dynamicResult[0] + 62;
            }
        }

        if ($nameIndex > 0) {
            return IntegerCodec::encode($nameIndex, 4, 0b0001_0000) . $this->encodeString($value, $valueLen);
        }

        return "\x10" . $this->encodeString($name, $nameLen) . $this->encodeString($value, $valueLen);
    }

    /**
     * @return non-empty-string
     */
    private function encodeString(string $value, int $rawLen): string
    {
        $estimatedBits = Huffman::estimateEncodedBits($value, $rawLen);
        $estimatedBytes = ($estimatedBits + 7) >> 3;

        if ($estimatedBytes >= $rawLen) {
            return IntegerCodec::encode($rawLen, 7, 0) . $value;
        }

        $huffman = Huffman::encode($value);
        $huffLen = strlen($huffman);

        if ($huffLen < $rawLen) {
            return IntegerCodec::encode($huffLen, 7, 0b1000_0000) . $huffman;
        }

        return IntegerCodec::encode($rawLen, 7, 0) . $value;
    }
}
