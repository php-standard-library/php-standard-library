<?php

declare(strict_types=1);

namespace Psl\MIME;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Stringable;

use function array_map;
use function array_values;
use function count;
use function implode;
use function strlen;
use function trim;
use function usort;

/**
 * Represents a weighted list of media ranges for content negotiation.
 *
 * This models the value of an HTTP Accept header (RFC 9110 §12.5.1), where each
 * media range has an associated quality weight indicating relative preference.
 *
 * Ranges are stored sorted by: (1) weight descending, (2) specificity descending
 * (exact > type/* > *\/*), (3) parameter count descending.
 *
 * @implements IteratorAggregate<int, MediaRange>
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-12.5.1
 *
 * @api
 */
final readonly class MediaPreferences implements Countable, IteratorAggregate, Stringable
{
    /**
     * The media ranges sorted by preference, highest first.
     *
     * @param list<MediaRange> $ranges Sorted by preference (highest first).
     */
    private function __construct(
        public array $ranges,
    ) {}

    /**
     * Parse an HTTP Accept header value into a preference-sorted list of media ranges.
     *
     * Comma-separated ranges are split respecting quoted strings, then each range is
     * parsed individually. Empty segments are silently ignored.
     *
     * @param string $input The raw Accept header value (e.g. "text/html, application/json; q=0.9, *\/*; q=0.1").
     *
     * @throws Exception\ParsingException If any range is malformed.
     * @throws Exception\InvalidMediaTypeComponentException If any component is invalid.
     */
    public static function parse(string $input): self
    {
        $input = trim($input, " \t\r\n");
        if ($input === '') {
            return new self([]);
        }

        $ranges = [];
        foreach (self::splitRanges($input) as $part) {
            $part = trim($part, " \t\r\n");
            if ($part === '') {
                continue;
            }

            $ranges[] = MediaRange::parse($part);
        }

        return new self(self::sort($ranges));
    }

    /**
     * Create a preference list from individual {@see MediaRange} instances.
     *
     * The ranges are automatically sorted by weight, specificity, and parameter count.
     */
    public static function from(MediaRange ...$ranges): self
    {
        $list = array_values($ranges);

        return new self(self::sort($list));
    }

    /**
     * Find the best matching range for a given {@see MediaType}.
     *
     * Returns the matching range with the highest weight. If multiple ranges have the
     * same weight, the most specific one wins (exact > type/* > *\/*).
     * Returns null if no range matches.
     */
    public function bestFor(MediaType $mediaType): null|MediaRange
    {
        $best = null;
        foreach ($this->ranges as $range) {
            if (!$range->matches($mediaType)) {
                continue;
            }

            if ($best === null) {
                $best = $range;

                continue;
            }

            if ($range->weight > $best->weight) {
                $best = $range;
            } elseif ($range->weight === $best->weight && $range->specificity() > $best->specificity()) {
                $best = $range;
            }
        }

        return $best;
    }

    /**
     * Perform server-driven content negotiation against available media types.
     *
     * For each available type, the most specific matching range determines the score (weight).
     * Types explicitly excluded (weight = 0.0) are never returned. Returns the available
     * type with the highest score, or null if no acceptable match exists.
     *
     * @param list<MediaType> $available The media types the server can produce.
     */
    public function negotiate(array $available): null|MediaType
    {
        $bestType = null;
        $bestWeight = -1.0;
        $bestSpecificity = 0;

        foreach ($available as $mediaType) {
            $match = $this->mostSpecificMatch($mediaType);
            if ($match === null) {
                continue;
            }

            if ($match->weight === 0.0) {
                continue;
            }

            if (
                $match->weight > $bestWeight
                || $match->weight === $bestWeight && $match->specificity() > $bestSpecificity
            ) {
                $bestType = $mediaType;
                $bestWeight = $match->weight;
                $bestSpecificity = $match->specificity();
            }
        }

        return $bestType;
    }

    /**
     * Return the number of media ranges in this preference list.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->ranges);
    }

    /**
     * Iterate over the media ranges in preference order.
     *
     * @return ArrayIterator<int, MediaRange>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->ranges);
    }

    /**
     * Serialize all ranges back into an Accept header value string.
     */
    public function toString(): string
    {
        return implode(', ', array_map(static fn(MediaRange $r): string => $r->toString(), $this->ranges));
    }

    /**
     * Serialize all ranges back into an Accept header value string.
     *
     * @see self::toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Find the most specific range that matches a media type.
     */
    private function mostSpecificMatch(MediaType $mediaType): null|MediaRange
    {
        $best = null;
        foreach ($this->ranges as $range) {
            if (!$range->matches($mediaType)) {
                continue;
            }

            if ($best === null || $range->specificity() > $best->specificity()) {
                $best = $range;
            }
        }

        return $best;
    }

    /**
     * Split an Accept header value on commas, respecting quoted strings.
     *
     * @return list<string>
     */
    private static function splitRanges(string $input): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];

            if ($char === '"' && ($i === 0 || $input[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
                $current .= $char;

                continue;
            }

            if ($char === ',' && !$inQuotes) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Sort ranges by weight desc, specificity desc, parameter count desc.
     *
     * @param list<MediaRange> $ranges
     *
     * @return list<MediaRange>
     */
    private static function sort(array $ranges): array
    {
        $sorted = $ranges;
        usort($sorted, static function (MediaRange $a, MediaRange $b): int {
            $weightCmp = $b->weight <=> $a->weight;
            if ($weightCmp !== 0) {
                return $weightCmp;
            }

            $specCmp = $b->specificity() <=> $a->specificity();
            if ($specCmp !== 0) {
                return $specCmp;
            }

            return $b->parameters->count() <=> $a->parameters->count();
        });

        return $sorted;
    }
}
