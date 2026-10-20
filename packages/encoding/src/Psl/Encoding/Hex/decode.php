<?php

declare(strict_types=1);

namespace Psl\Encoding\Hex;

use Psl\Encoding\Exception;

use function ctype_xdigit;
use function hex2bin;
use function strlen;

/**
 * Convert a hexadecimal string into a binary string.
 *
 * Hex ( Base16 ) character set:
 *  [0-9]      [a-f]      [A-F]
 *  0x30-0x39, 0x61-0x66, 0x41-0x46
 *
 * @pure
 *
 * @throws Exception\RangeException If the hexadecimal string contains characters outside the base16 range,
 *                                  or an odd number of characters.
 *
 * @api
 */
function decode(string $hexadecimal): string
{
    // ctype_xdigit() returns false for the empty string, which is nevertheless valid hex.
    if ('' === $hexadecimal) {
        return '';
    }

    if (!ctype_xdigit($hexadecimal)) {
        throw new Exception\RangeException(
            'The given hexadecimal string contains characters outside the base16 range.',
        );
    }

    $hexLen = strlen($hexadecimal);
    if (($hexLen & 1) !== 0) {
        throw new Exception\RangeException('Expected an even number of hexadecimal characters.');
    }

    return (string) hex2bin($hexadecimal);
}
