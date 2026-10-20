<?php

declare(strict_types=1);

namespace Psl\Crypto\Hkdf;

use Psl\Hash\Hmac;
use SensitiveParameter;

use function hash_hmac;
use function str_repeat;
use function strlen;

/**
 * HKDF-Extract: Extract a pseudorandom key from input keying material.
 *
 * @see https://tools.ietf.org/html/rfc5869#section-2.2
 *
 * @return non-empty-string
 *
 * @api
 */
function extract(
    #[SensitiveParameter]
    string $inputKeyingMaterial,
    string $salt = '',
    Hmac\Algorithm $algorithm = Hmac\Algorithm::Sha256,
): string {
    if ($salt === '') {
        $hashLength = strlen(hash_hmac($algorithm->value, '', '', true));

        /** @var non-empty-string $salt */
        $salt = str_repeat("\x00", $hashLength);
    }

    /** @var non-empty-string */
    return hash_hmac($algorithm->value, $inputKeyingMaterial, $salt, true);
}
