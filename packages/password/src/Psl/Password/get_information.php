<?php

declare(strict_types=1);

namespace Psl\Password;

use function password_get_info;
use function preg_match;

use const PASSWORD_ARGON2_DEFAULT_MEMORY_COST;
use const PASSWORD_ARGON2_DEFAULT_THREADS;
use const PASSWORD_ARGON2_DEFAULT_TIME_COST;
use const PASSWORD_ARGON2I;
use const PASSWORD_ARGON2ID;
use const PASSWORD_BCRYPT;
use const PASSWORD_BCRYPT_DEFAULT_COST;

/**
 * Returns information about the given hash.
 *
 * When passed in a valid hash created by an algorithm supported by `Psl\Password\hash()`,
 * this function will return an array of information about that hash.
 *
 * Bcrypt hashes with the "$2a$" and "$2b$" prefixes, which other bcrypt implementations produce,
 * and which {@see verify()} accepts, are reported as {@see Algorithm::Bcrypt}.
 *
 * @throws Exception\InvalidArgumentException If the given string is not a hash created by a supported algorithm.
 *
 * @return array{algorithm: Algorithm, options: array{cost: int}|array{memory_cost: int, time_cost: int, threads: int}}
 *
 * @pure
 *
 * @api
 */
function get_information(string $hash): array
{
    /** @var array{algo: null|string, options: array<string, array-key>} $information */
    $information = password_get_info($hash);
    $algorithm = $information['algo'];
    if (PASSWORD_BCRYPT === $algorithm) {
        return [
            'algorithm' => Algorithm::Bcrypt,
            'options' => [
                'cost' => (int) ($information['options']['cost'] ?? PASSWORD_BCRYPT_DEFAULT_COST),
            ],
        ];
    }

    // `password_get_info()` only recognizes the "$2y$" prefix, while `verify()` also accepts bcrypt hashes
    // with the "$2a$" and "$2b$" prefixes, which other bcrypt implementations produce.
    $matches = [];
    if (null === $algorithm && 1 === preg_match('/^\$2[ab]\$(\d{2})\$[.\/A-Za-z0-9]{53}$/D', $hash, $matches)) {
        return [
            'algorithm' => Algorithm::Bcrypt,
            'options' => [
                'cost' => (int) $matches[1],
            ],
        ];
    }

    if (PASSWORD_ARGON2I !== $algorithm && PASSWORD_ARGON2ID !== $algorithm) {
        throw new Exception\InvalidArgumentException(
            'The given string is not a hash created by a supported password hashing algorithm.',
        );
    }

    return [
        'algorithm' => PASSWORD_ARGON2ID === $algorithm ? Algorithm::Argon2id : Algorithm::Argon2i,
        'options' => [
            'memory_cost' => (int) ($information['options']['memory_cost'] ?? PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
            'time_cost' => (int) ($information['options']['time_cost'] ?? PASSWORD_ARGON2_DEFAULT_TIME_COST),
            'threads' => (int) ($information['options']['threads'] ?? PASSWORD_ARGON2_DEFAULT_THREADS),
        ],
    ];
}
