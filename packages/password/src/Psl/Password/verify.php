<?php

declare(strict_types=1);

namespace Psl\Password;

use SensitiveParameter;

use function password_verify;

/**
 * Verifies that the given hash matches the given password.
 *
 * Note that `Psl\Password\hash` returns the algorithm, cost and salt as part of the returned hash.
 * Therefore, all information that's needed to verify the hash is included in it. This allows
 * the verify function to verify the hash without needing separate storage for the salt or
 * algorithm information.
 *
 * This function is safe against timing attacks.
 *
 * @pure
 *
 * @api
 */
function verify(#[SensitiveParameter] string $password, string $hash): bool
{
    return password_verify($password, $hash);
}
