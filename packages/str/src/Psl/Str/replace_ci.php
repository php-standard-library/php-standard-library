<?php

declare(strict_types=1);

namespace Psl\Str;

use function error_clear_last;
use function error_get_last;
use function preg_last_error_msg;
use function preg_quote;
use function preg_split;

/**
 * Returns the '$haystack' string with all occurrences of `$needle` replaced by
 * `$replacement` (case-insensitive).
 *
 * @pure
 *
 * @throws Exception\InvalidArgumentException if $haystack or $needle is not a valid UTF-8 string.
 *
 * @api
 */
function replace_ci(string $haystack, string $needle, string $replacement, Encoding $encoding = Encoding::Utf8): string
{
    if ('' === $haystack || '' === $needle || null === namespace\search_ci($haystack, $needle, 0, $encoding)) {
        return $haystack;
    }

    // `preg_split()` does not emit a warning for an invalid subject: make sure an earlier error is not reported.
    error_clear_last();
    $pieces = @preg_split('{' . preg_quote($needle, '/') . '}iu', $haystack, -1);
    if (false === $pieces) {
        $error = error_get_last();
        throw new Exception\InvalidArgumentException($error['message'] ?? preg_last_error_msg());
    }

    return namespace\join($pieces, $replacement);
}
