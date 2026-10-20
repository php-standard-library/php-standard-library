<?php

declare(strict_types=1);

namespace Psl\HPACK;

use Psl\HPACK\Exception\DecodingException;
use Psl\HPACK\Exception\HeaderListSizeException;
use Psl\HPACK\Exception\IntegerOverflowException;
use Psl\HPACK\Exception\InvalidSizeException;
use Psl\HPACK\Exception\InvalidTableIndexException;
use Psl\HPACK\Internal\DynamicTable;
use Psl\HPACK\Internal\Huffman;
use Psl\HPACK\Internal\IntegerCodec;
use Psl\HPACK\Internal\StaticTable;

use function min;
use function ord;
use function strlen;
use function substr;

/**
 * HPACK decoder per RFC 7541.
 *
 * Decompresses HPACK-encoded header blocks back into header field lists.
 * Handles all RFC 7541 representations: indexed, literal with incremental
 * indexing, literal without indexing, literal never indexed, and dynamic
 * table size updates.
 *
 * The decoder is stateful - it maintains a dynamic table that evolves across
 * multiple decode() calls within the same HTTP/2 connection. Each connection
 * should use its own Decoder instance.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7541
 *
 * @api
 */
final class Decoder
{
    private DynamicTable $dynamicTable;

    private int $maxHeaderListSize;

    private int $maxTableSize;

    /**
     * Create a new HPACK decoder.
     *
     * @param int<0, max> $maxTableSize Maximum dynamic table size in bytes (default: 4096 per RFC 7541 Section 4.2).
     *                                  The decoder rejects table size updates that exceed this limit.
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

        $this->maxTableSize = $maxTableSize;
        $this->maxHeaderListSize = $maxHeaderListSize;
        $this->dynamicTable = new DynamicTable($maxTableSize);
    }

    /**
     * Decode an HPACK-encoded header block into a list of header fields.
     *
     * Processes the binary header block sequentially, handling table size
     * updates (which must appear at the start of the block), indexed references,
     * and literal representations. The dynamic table is updated as headers
     * with incremental indexing are decoded.
     *
     * @param string $encoded The raw HPACK-encoded header block bytes.
     *
     * @throws DecodingException If the encoded data is malformed, contains invalid Huffman data, or has protocol violations.
     * @throws HeaderListSizeException If the total decompressed header list size exceeds the configured limit.
     * @throws IntegerOverflowException If an encoded integer exceeds PHP_INT_MAX.
     * @throws InvalidTableIndexException If a header references an invalid static or dynamic table index.
     *
     * @return list<Header>
     */
    public function decode(string $encoded): array
    {
        $headers = [];
        $offset = 0;
        $length = strlen($encoded);
        $totalSize = 0;
        $pastFirstHeader = false;
        $tableSizeUpdates = 0;

        while ($offset < $length) {
            $byte = ord($encoded[$offset]);

            if (($byte & 0b1110_0000) === 0b0010_0000) {
                if ($pastFirstHeader) {
                    throw DecodingException::forTableSizeUpdateNotAtBlockStart();
                }

                $tableSizeUpdates++;
                if ($tableSizeUpdates > 2) {
                    throw DecodingException::forTooManyTableSizeUpdates();
                }

                [$newSize, $offset] = IntegerCodec::decode($encoded, $offset, 5, $length);

                if ($newSize > $this->maxTableSize) {
                    throw DecodingException::forTableSizeExceedsLimit();
                }

                $this->dynamicTable->setMaxSize($newSize);
                continue;
            }

            $pastFirstHeader = true;
            $sensitive = false;

            if (($byte & 0b1000_0000) !== 0) {
                // Indexed header field (Section 6.1), 7-bit prefix.
                $index = $byte & 0x7F;
                if ($index < 0x7F) {
                    $offset++;
                } else {
                    [$index, $offset] = IntegerCodec::decode($encoded, $offset, 7, $length);
                }

                [$name, $value] = $this->lookupIndex($index);
            } elseif (($byte & 0b0100_0000) !== 0) {
                // Literal with incremental indexing (Section 6.2.1), 6-bit prefix.
                $index = $byte & 0x3F;
                if ($index < 0x3F) {
                    $offset++;
                } else {
                    [$index, $offset] = IntegerCodec::decode($encoded, $offset, 6, $length);
                }

                if ($index > 0) {
                    [$name, $_] = $this->lookupIndex($index);
                } else {
                    /** @var non-empty-lowercase-string $name */
                    [$name, $offset] = $this->decodeString($encoded, $offset, $length);
                }

                [$value, $offset] = $this->decodeString($encoded, $offset, $length);
                $this->dynamicTable->insert($name, strlen($name), $value, strlen($value));
            } elseif (($byte & 0b1111_0000) === 0b0001_0000) {
                // Literal never indexed (Section 6.2.3), 4-bit prefix.
                $index = $byte & 0x0F;
                if ($index < 0x0F) {
                    $offset++;
                } else {
                    [$index, $offset] = IntegerCodec::decode($encoded, $offset, 4, $length);
                }

                if ($index > 0) {
                    [$name, $_] = $this->lookupIndex($index);
                } else {
                    [$name, $offset] = $this->decodeString($encoded, $offset, $length);
                }

                [$value, $offset] = $this->decodeString($encoded, $offset, $length);
                $sensitive = true;
            } else {
                // Literal without indexing (Section 6.2.2), 4-bit prefix.
                $index = $byte & 0x0F;
                if ($index < 0x0F) {
                    $offset++;
                } else {
                    [$index, $offset] = IntegerCodec::decode($encoded, $offset, 4, $length);
                }

                if ($index > 0) {
                    [$name, $_] = $this->lookupIndex($index);
                } else {
                    [$name, $offset] = $this->decodeString($encoded, $offset, $length);
                }

                [$value, $offset] = $this->decodeString($encoded, $offset, $length);
            }

            $totalSize += strlen($name) + strlen($value) + 32;
            if ($totalSize > $this->maxHeaderListSize) {
                throw HeaderListSizeException::forExceededLimit($totalSize, $this->maxHeaderListSize);
            }

            /** @var non-empty-lowercase-string $name */
            $headers[] = new Header($name, $value, $sensitive);
        }

        return $headers;
    }

    /**
     * Update the maximum allowed dynamic table size.
     *
     * This should be called when the HTTP/2 SETTINGS_HEADER_TABLE_SIZE
     * parameter changes. The decoder will reject any table size update
     * in the encoded stream that exceeds this limit.
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

        $this->maxTableSize = $maxSize;
        $this->dynamicTable->setMaxSize(min($maxSize, $this->dynamicTable->maxSize()));
    }

    /**
     * @param int<0, max> $index
     *
     * @throws InvalidTableIndexException If the index is zero or out of range.
     *
     * @return array{non-empty-lowercase-string, string}
     */
    private function lookupIndex(int $index): array
    {
        if ($index === 0) {
            throw InvalidTableIndexException::forZeroIndex();
        }

        if ($index <= 61) {
            $entry = StaticTable::get($index);
            if ($entry !== null) {
                return $entry;
            }
        }

        /** @var int<0, max> $dynamicIndex */
        $dynamicIndex = $index - 62;
        $entry = $this->dynamicTable->get($dynamicIndex);
        if ($entry !== null) {
            return $entry;
        }

        $maxIndex = 61 + $this->dynamicTable->count();
        throw InvalidTableIndexException::forOutOfRange($index, $maxIndex);
    }

    /**
     * @param int<0, max> $offset
     *
     * @return array{string, int<0, max>}
     *
     * @throws DecodingException
     * @throws IntegerOverflowException
     */
    private function decodeString(string $data, int $offset, int $dataLength): array
    {
        if ($offset >= $dataLength) {
            throw DecodingException::forUnexpectedEndOfData();
        }

        $huffman = (ord($data[$offset]) & 0b1000_0000) !== 0;
        [$stringLength, $offset] = IntegerCodec::decode($data, $offset, 7, $dataLength);

        if (($offset + $stringLength) > $dataLength) {
            throw DecodingException::forInvalidStringLength();
        }

        $stringData = substr($data, $offset, $stringLength);
        $offset += $stringLength;

        if ($huffman) {
            $stringData = Huffman::decode($stringData);
        }

        return [$stringData, $offset];
    }
}
