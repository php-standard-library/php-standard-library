<?php

declare(strict_types=1);

namespace Psl\Encoding\EncodedWord\Internal;

use Psl\Encoding\Exception;
use ValueError;

use function mb_convert_encoding;
use function strtoupper;

/**
 * Convert decoded text from the given charset to UTF-8.
 *
 * @throws Exception\ParsingException If the charset is not supported.
 *
 * @internal
 */
function convert_charset(string $charset, string $text): string
{
    $charset = strtoupper($charset);

    if ($charset === 'UTF-8' || $charset === 'US-ASCII' || $charset === 'ASCII') {
        return $text;
    }

    try {
        $converted = mb_convert_encoding($text, 'UTF-8', $charset);
    } catch (ValueError) { // @mago-expect analysis:avoid-catching-error
        throw Exception\ParsingException::forUnsupportedCharset($charset);
    }

    return $converted !== false ? $converted : $text;
}
