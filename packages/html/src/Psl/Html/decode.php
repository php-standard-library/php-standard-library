<?php

declare(strict_types=1);

namespace Psl\Html;

use function html_entity_decode;

use const ENT_HTML401;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Convert HTML entities to their corresponding characters.
 *
 * Named entities are resolved using the HTML 4.01 table, the same table used by {@see encode()},
 * and both double and single quotes are decoded.
 *
 * @param Encoding $encoding defines character set used in conversion.
 *
 * @pure
 *
 * @api
 */
function decode(string $html, Encoding $encoding = Encoding::Utf8): string
{
    return html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encoding->value);
}
