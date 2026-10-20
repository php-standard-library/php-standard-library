<?php

declare(strict_types=1);

namespace Psl\Encoding\EncodedWord\Internal;

use Psl\Str\Encoding;

use function implode;
use function mb_str_split;
use function strlen;

/**
 * Encode text as Q-encoded words per RFC 2047.
 *
 * Per RFC 2047 §5, each encoded-word must contain whole characters, so the text is
 * split on character boundaries of the given charset, never inside a multi-byte character.
 *
 * @internal
 */
function encode_q_words(string $text, string $prefix, string $suffix, int $maxPayload, Encoding $charset): string
{
    $words = [];
    $currentWord = '';
    $currentLen = 0;

    foreach (mb_str_split($text, 1, $charset->value) as $character) {
        $encoded = '';
        for ($i = 0, $length = strlen($character); $i < $length; $i++) {
            $encoded .= namespace\q_encode_byte($character[$i]);
        }

        $encodedLen = strlen($encoded);
        if (($currentLen + $encodedLen) > $maxPayload && $currentWord !== '') {
            $words[] = $prefix . $currentWord . $suffix;
            $currentWord = '';
            $currentLen = 0;
        }

        $currentWord .= $encoded;
        $currentLen += $encodedLen;
    }

    if ($currentWord !== '') {
        $words[] = $prefix . $currentWord . $suffix;
    }

    return implode(' ', $words);
}
