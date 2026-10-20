<?php

declare(strict_types=1);

namespace Psl\Crypto\Hkdf;

use Psl\Crypto\Exception;
use Psl\Hash\Hmac;
use SensitiveParameter;

use function chr;
use function hash_hmac;
use function sodium_memzero;
use function strlen;
use function substr;

/**
 * HKDF-Expand: Expand a pseudorandom key to the desired length.
 *
 * @param non-empty-string $pseudoRandomKey
 * @param positive-int $length Desired output length in bytes.
 *
 * @throws Exception\RuntimeException If the requested length exceeds the maximum.
 *
 * @see https://tools.ietf.org/html/rfc5869#section-2.3
 *
 * @return non-empty-string
 *
 * @api
 */
function expand(
    #[SensitiveParameter]
    string $pseudoRandomKey,
    string $info = '',
    int $length = 32,
    Hmac\Algorithm $algorithm = Hmac\Algorithm::Sha256,
): string {
    $hashLength = strlen(hash_hmac($algorithm->value, '', '', true));

    $maxLength = 255 * $hashLength;
    if ($length > $maxLength) {
        throw new Exception\RuntimeException('Requested HKDF output length exceeds maximum.');
    }

    $t = '';
    $okm = '';
    $counter = 1;

    while (strlen($okm) < $length) {
        $previous = $t;
        $t = hash_hmac($algorithm->value, $t . $info . chr($counter), $pseudoRandomKey, true);
        if ($previous !== '') {
            sodium_memzero($previous);
        }

        $okm .= $t;
        $counter++;
    }

    sodium_memzero($t);
    $result = substr($okm, 0, $length);
    sodium_memzero($okm);

    /** @var non-empty-string */
    return $result;
}
