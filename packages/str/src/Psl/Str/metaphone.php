<?php

declare(strict_types=1);

namespace Psl\Str;

use function metaphone as php_metaphone;

/**
 * Calculate the metaphone key of a string.
 *
 * @param int<0, max> $phonemes This parameter restricts the returned metaphone key to phonemes characters in length.
 *                              the default value of 0 means no restriction.
 *
 * @return string the metaphone key as a string
 *
 * @pure
 *
 * @deprecated The underlying PHP metaphone() function is deprecated as of PHP 8.6.
 *             Use a userland phonetic matching library instead.
 *
 * @api
 */
function metaphone(string $string, int $phonemes = 0): string
{
    return php_metaphone($string, $phonemes);
}
