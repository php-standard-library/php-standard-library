<?php

declare(strict_types=1);

namespace Psl\Str;

use function array_keys;
use function array_values;
use function implode;
use function preg_match;
use function str_ireplace;

/**
 * Returns the 'haystack' string with all occurrences of the keys of
 * `$replacements` replaced by the corresponding values (case-insensitive).
 *
 * Replacements are applied in order, with the same case-insensitive matching
 * as {@see replace_ci()}.
 *
 * @param array<string, string> $replacements
 *
 * @throws Exception\InvalidArgumentException If $haystack or a needle is not a valid UTF-8 string.
 *
 * @pure
 *
 * @api
 */
function replace_every_ci(string $haystack, array $replacements, Encoding $encoding = Encoding::Utf8): string
{
    $needles = array_keys($replacements);
    $values = array_values($replacements);
    // When the haystack, the needles and the replacements (which later needles are matched against) are all
    // ASCII, ASCII case folding matches exactly what Unicode case folding matches: take the fast path.
    if (
        $encoding === Encoding::Ascii
        || $encoding === Encoding::Utf8
        && 1 !== preg_match('/[\x80-\xFF]/', $haystack . implode('', $needles) . implode('', $values))
    ) {
        return str_ireplace($needles, $values, $haystack);
    }

    foreach ($replacements as $needle => $replacement) {
        // @mago-expect analysis:redundant-cast - numeric-string keys are stored as integers by PHP.
        $needle = (string) $needle;
        if ('' === $needle) {
            continue;
        }

        $haystack = namespace\replace_ci($haystack, $needle, $replacement, $encoding);
    }

    return $haystack;
}
