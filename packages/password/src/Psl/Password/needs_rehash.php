<?php

declare(strict_types=1);

namespace Psl\Password;

use Psl;

use function password_needs_rehash;

/**
 * Checks if the given hash needs to be rehashed to match the given algorithm and options.
 *
 * Returns true when the hash was not created with the given algorithm and options (including
 * when it is not a recognized hash at all), in which case the password should be hashed again
 * with {@see hash()}, typically right after a successful {@see verify()}.
 *
 * @param array{cost?: int}|array{memory_cost?: int, time_cost?: int, threads?: int} $options
 *
 * @pure
 *
 * @api
 */
function needs_rehash(string $hash, Algorithm $algorithm = Algorithm::Default, array $options = []): bool
{
    return password_needs_rehash($hash, $algorithm->getBuiltinConstantValue(), $options);
}
