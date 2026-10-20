<?php

declare(strict_types=1);

namespace Psl\Str\Byte;

use function chr as php_chr;

/**
 * Return a specific character.
 *
 * Values outside the 0..255 range wrap around modulo 256, so `-1` gives `"\xFF"` and `256` gives `"\x00"`.
 *
 * @pure
 *
 * @api
 */
function chr(int $ascii): string
{
    return php_chr($ascii & 0xFF);
}
