<?php

declare(strict_types=1);

namespace Psl\Ansi;

use function preg_replace;

/**
 * Strips all ANSI escape sequences from the given text.
 *
 * Recognizes the 7-bit escape sequences of ECMA-48: control sequences
 * (`ESC [`, including private parameters and intermediate bytes), control
 * strings (OSC `ESC ]`, DCS `ESC P`, SOS `ESC X`, PM `ESC ^` and APC `ESC _`,
 * terminated by BEL or ST), single shifts (`ESC N x`, `ESC O x`), and other
 * escape sequences (e.g. `ESC 7`, `ESC c`, `ESC ( B`). Incomplete sequences,
 * such as a control string without its terminator or a single shift without
 * the character it applies to, are left untouched.
 *
 * @pure
 *
 * @api
 */
function strip(string $text): string
{
    return preg_replace(Internal\ESCAPE_SEQUENCE_PATTERN, '', $text) ?? $text;
}
