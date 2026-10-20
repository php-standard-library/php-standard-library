<?php

declare(strict_types=1);

namespace Psl\Encoding\EncodedWord;

use Psl\Encoding\Exception;

use function preg_match_all;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const PREG_SET_ORDER;

/**
 * Decode RFC 2047 encoded-words in a header value.
 *
 * Handles both B-encoding (base64) and Q-encoding. Per RFC 2047 §6.2,
 * linear whitespace (spaces, tabs and line breaks) between adjacent encoded-words is removed.
 *
 * @throws Exception\ParsingException If an encoded-word is malformed.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2047
 *
 * @api
 */
function decode(string $input): string
{
    if ($input === '') {
        return '';
    }

    $pattern = '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> $matches */
    $matches = [];
    if (preg_match_all($pattern, $input, $matches, PREG_SET_ORDER) === 0) {
        return $input;
    }

    $result = '';
    $lastEnd = 0;
    $lastWasEncoded = false;

    foreach ($matches as $match) {
        $full = $match[0];
        $charset = $match[1];
        $encoding = $match[2];
        $encodedText = $match[3];

        $matchStart = strpos($input, $full, $lastEnd);
        if ($matchStart === false) {
            continue;
        }

        $matchEnd = $matchStart + strlen($full);

        $between = substr($input, $lastEnd, $matchStart - $lastEnd);

        if (!$lastWasEncoded || trim($between, " \t\r\n") !== '') {
            $result .= $between;
        }

        $decoded = Internal\decode_payload($encoding, $encodedText);
        $result .= Internal\convert_charset($charset, $decoded);

        $lastEnd = $matchEnd;
        $lastWasEncoded = true;
    }

    $result .= substr($input, $lastEnd);

    return $result;
}
