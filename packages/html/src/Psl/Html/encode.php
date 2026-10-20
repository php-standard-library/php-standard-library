<?php

declare(strict_types=1);

namespace Psl\Html;

use function htmlentities;

use const ENT_HTML401;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Convert all applicable characters to HTML entities.
 *
 * Named entities are taken from the HTML 4.01 table, and both double and single quotes are encoded.
 *
 * Invalid code unit sequences for the given encoding are replaced with a Unicode Replacement
 * Character (U+FFFD, or `&#xFFFD;` for encodings other than UTF-8), instead of discarding the
 * whole string.
 *
 * @param bool $doubleEncoding If set to false, this function will not
 *                              encode existing html entities.
 * @param Encoding $encoding defines character set used in conversion.
 *
 * @psalm-taint-escape html
 *
 * @pure
 *
 * @api
 */
function encode(string $html, bool $doubleEncoding = true, Encoding $encoding = Encoding::Utf8): string
{
    return htmlentities($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encoding->value, $doubleEncoding);
}
