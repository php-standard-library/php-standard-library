<?php

declare(strict_types=1);

namespace Psl\Str;

use function mb_strimwidth;

/**
 * Returns a substring truncated to the given display width as defined by {@see mb_strimwidth()}.
 *
 * See {@see width()}.
 *
 * For grapheme-based slicing, use {@see Grapheme\slice()}.
 *
 * @param int<0, max> $offset Codepoint offset to start from.
 * @param int<0, max> $width Maximum display width of the result.
 *
 * @throws Exception\InvalidArgumentException If the width is negative.
 *
 * @pure
 *
 * @api
 */
function width_slice(string $string, int $offset, int $width, Encoding $encoding = Encoding::Utf8): string
{
    // @mago-expect analysis:redundant-comparison,impossible-condition - runtime guard for untyped callers
    if ($width < 0) {
        throw new Exception\InvalidArgumentException('$width must be a non-negative integer.');
    }

    return mb_strimwidth($string, $offset, $width, '', $encoding->value);
}
