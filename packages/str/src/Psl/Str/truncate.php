<?php

declare(strict_types=1);

namespace Psl\Str;

use function mb_strimwidth;

/**
 * Truncate a string to the given display width as defined by {@see mb_strimwidth()}.
 *
 * For grapheme-based slicing, use {@see Grapheme\slice()}.
 *
 * @param int $offset The start position offset in codepoints (0-indexed).
 * @param int<0, max> $width The maximum display width of the result.
 * @param null|string $trimMarker Appended to the result when the string is truncated.
 *
 * @throws Exception\InvalidArgumentException If the width is negative.
 * @throws Exception\OutOfBoundsException If the offset is out-of-bounds.
 *
 * @pure
 *
 * @api
 */
function truncate(
    string $string,
    int $offset,
    int $width,
    null|string $trimMarker = null,
    Encoding $encoding = Encoding::Utf8,
): string {
    // @mago-expect analysis:redundant-comparison,impossible-condition - runtime guard for untyped callers
    if ($width < 0) {
        throw new Exception\InvalidArgumentException('$width must be a non-negative integer.');
    }

    $offset = Internal\validate_offset($offset, namespace\length($string, $encoding));

    return mb_strimwidth($string, $offset, $width, $trimMarker ?? '', $encoding->value);
}
