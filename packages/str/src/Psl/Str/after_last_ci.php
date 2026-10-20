<?php

declare(strict_types=1);

namespace Psl\Str;

/**
 * @throws Exception\OutOfBoundsException If the $offset is out-of-bounds.
 *
 * @pure
 *
 * @api
 */
function after_last_ci(
    string $haystack,
    string $needle,
    int $offset = 0,
    Encoding $encoding = Encoding::Utf8,
): null|string {
    $position = namespace\search_last_ci($haystack, $needle, $offset, $encoding);
    if (null === $position) {
        return null;
    }

    $position += namespace\length($needle, $encoding);

    return namespace\slice($haystack, $position, null, $encoding);
}
