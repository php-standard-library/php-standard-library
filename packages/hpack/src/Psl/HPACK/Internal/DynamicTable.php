<?php

declare(strict_types=1);

namespace Psl\HPACK\Internal;

use function array_slice;
use function strlen;

/**
 * HPACK dynamic table per RFC 7541 Section 4.
 *
 * Uses append + head offset to achieve O(1) insert and O(1) evict.
 * Index 0 = most recently inserted entry (last element in logical view).
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7541#section-4
 *
 * @internal
 */
final class DynamicTable
{
    /**
     * Per-entry overhead in bytes as defined by RFC 7541 Section 4.1.
     */
    private const int ENTRY_OVERHEAD = 32;

    /**
     * @var list<array{non-empty-lowercase-string, string}>
     */
    private array $entries = [];

    /**
     * @var int Index past the last logical entry in the entries array.
     */
    private int $tail = 0;

    /**
     * @var int<0, max> Index of the oldest logical entry in the entries array.
     */
    private int $head = 0;

    /**
     * @var int Current table size in bytes (sum of all entry sizes including overhead).
     */
    private int $size = 0;

    /**
     * @var int Maximum allowed table size in bytes.
     */
    private int $maxSize;

    /**
     * Create a new dynamic table with the given maximum size.
     *
     * @param int $maxSize Maximum table size in bytes (default: 4096).
     */
    public function __construct(int $maxSize = 4_096)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Insert a new entry into the dynamic table, evicting older entries as needed.
     *
     * If the entry size exceeds the maximum table size, the table is cleared.
     *
     * @param non-empty-lowercase-string $name The header field name.
     * @param string $value The header field value.
     */
    public function insert(string $name, int $nameLen, string $value, int $valueLen): void
    {
        $entrySize = $nameLen + $valueLen + self::ENTRY_OVERHEAD;

        if ($entrySize > $this->maxSize) {
            $this->entries = [];
            $this->head = 0;
            $this->tail = 0;
            $this->size = 0;
            return;
        }

        while (($this->size + $entrySize) > $this->maxSize && $this->head < $this->tail) {
            $this->evict();
        }

        $this->entries[] = [$name, $value];
        $this->tail++;
        $this->size += $entrySize;

        if ($this->head > 256) {
            $this->entries = array_slice($this->entries, $this->head);
            $this->tail -= $this->head;
            $this->head = 0;
        }
    }

    /**
     * @param non-negative-int $index
     *
     * @return null|array{non-empty-lowercase-string, string}
     */
    public function get(int $index): null|array
    {
        $logicalCount = $this->tail - $this->head;
        if ($index >= $logicalCount) {
            return null;
        }

        /** @var int<0, max> $realIndex */
        $realIndex = $this->tail - 1 - $index;
        return $this->entries[$realIndex];
    }

    /**
     * @param non-empty-lowercase-string $name
     *
     * @return null|array{int, bool} [0-based index, full match]
     */
    public function search(string $name, string $value): null|array
    {
        $nameMatch = null;
        $tail = $this->tail;

        for ($i = $tail - 1; $i >= $this->head; $i--) {
            /** @var int<0, max> $i */
            [$n, $v] = $this->entries[$i];
            if ($n !== $name) {
                continue;
            }

            $index = $tail - 1 - $i;
            if ($v === $value) {
                return [$index, true];
            }

            $nameMatch ??= $index;
        }

        return $nameMatch !== null ? [$nameMatch, false] : null;
    }

    /**
     * Update the maximum table size, evicting entries if necessary.
     *
     * @param int $maxSize The new maximum table size in bytes.
     */
    public function setMaxSize(int $maxSize): void
    {
        $this->maxSize = $maxSize;

        while ($this->size > $this->maxSize && $this->head < $this->tail) {
            $this->evict();
        }
    }

    /**
     * Return the number of entries currently in the table.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->tail - $this->head;
    }

    /**
     * Return the current table size in bytes (including per-entry overhead).
     *
     * @return int
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Return the maximum table size in bytes.
     */
    public function maxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Evict the oldest entry from the table.
     */
    private function evict(): void
    {
        if ($this->head >= $this->tail) {
            return;
        }

        $entry = $this->entries[$this->head];
        $this->head++;
        $this->size -= strlen($entry[0]) + strlen($entry[1]) + self::ENTRY_OVERHEAD;
    }
}
