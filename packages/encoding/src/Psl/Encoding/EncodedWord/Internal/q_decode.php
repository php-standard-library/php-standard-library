<?php

declare(strict_types=1);

namespace Psl\Encoding\EncodedWord\Internal;

use function chr;
use function ctype_xdigit;
use function hexdec;
use function strlen;
use function substr;

/**
 * Q-decode an encoded text per RFC 2047 §4.2.
 *
 * An "=" that is not followed by two hexadecimal digits is kept as a literal character,
 * following the robustness advice of RFC 2045 §6.7 for the quoted-printable encoding Q is based on.
 *
 * @internal
 */
function q_decode(string $text): string
{
    $result = '';
    $len = strlen($text);
    $i = 0;

    while ($i < $len) {
        $char = $text[$i];

        if ($char === '_') {
            $result .= ' ';
            $i++;

            continue;
        }

        if ($char === '=' && ($i + 2) < $len) {
            $hex = substr($text, $i + 1, 2);
            if (ctype_xdigit($hex)) {
                $result .= chr((int) hexdec($hex));
                $i += 3;

                continue;
            }
        }

        $result .= $char;
        $i++;
    }

    return $result;
}
