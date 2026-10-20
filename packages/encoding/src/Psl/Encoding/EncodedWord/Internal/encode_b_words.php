<?php

declare(strict_types=1);

namespace Psl\Encoding\EncodedWord\Internal;

use Psl\Str\Encoding;

use function base64_encode;
use function implode;
use function intdiv;
use function mb_str_split;
use function strlen;

/**
 * Encode text as B-encoded words per RFC 2047.
 *
 * Per RFC 2047 §5, each encoded-word must contain whole characters, so the text is
 * split on character boundaries of the given charset, never inside a multi-byte character.
 *
 * @internal
 */
function encode_b_words(string $text, string $prefix, string $suffix, int $maxPayload, Encoding $charset): string
{
    $maxRaw = intdiv($maxPayload, 4) * 3;
    $words = [];
    $chunk = '';

    foreach (mb_str_split($text, 1, $charset->value) as $character) {
        if ($chunk !== '' && (strlen($chunk) + strlen($character)) > $maxRaw) {
            $words[] = $prefix . base64_encode($chunk) . $suffix;
            $chunk = '';
        }

        $chunk .= $character;
    }

    if ($chunk !== '') {
        $words[] = $prefix . base64_encode($chunk) . $suffix;
    }

    return implode(' ', $words);
}
