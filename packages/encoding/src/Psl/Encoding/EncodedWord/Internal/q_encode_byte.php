<?php

declare(strict_types=1);

namespace Psl\Encoding\EncodedWord\Internal;

use function dechex;
use function ord;
use function str_pad;
use function strtoupper;

use const STR_PAD_LEFT;

/**
 * Q-encode a single byte per RFC 2047 §4.2.
 *
 * Only the characters that RFC 2047 §5 (3) allows in an encoded-word used within a phrase
 * (ASCII letters, digits, "!", "*", "+", "-" and "/") are kept literally, so that the result is
 * also safe in structured headers such as display names, where e.g. "," "<" or "(" would otherwise
 * change how the header is parsed. Spaces become "_", everything else is "=XX" encoded.
 *
 * @internal
 */
function q_encode_byte(string $byte): string
{
    if ($byte === ' ') {
        return '_';
    }

    $ord = ord($byte);
    if (
        $ord >= 0x30 && $ord <= 0x39
        || $ord >= 0x41 && $ord <= 0x5A
        || $ord >= 0x61 && $ord <= 0x7A
        || $byte === '!'
        || $byte === '*'
        || $byte === '+'
        || $byte === '-'
        || $byte === '/'
    ) {
        return $byte;
    }

    return '=' . strtoupper(str_pad(dechex($ord), 2, '0', STR_PAD_LEFT));
}
