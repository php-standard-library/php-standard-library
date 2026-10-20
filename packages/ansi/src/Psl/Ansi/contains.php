<?php

declare(strict_types=1);

namespace Psl\Ansi;

use function preg_match;

/**
 * Checks whether the given text contains any ANSI escape sequences.
 *
 * Recognizes the same sequences as {@see strip()}.
 *
 * @pure
 *
 * @api
 */
function contains(string $text): bool
{
    return preg_match(Internal\ESCAPE_SEQUENCE_PATTERN, $text) === 1;
}
