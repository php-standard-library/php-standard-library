<?php

declare(strict_types=1);

namespace Psl\Env;

use Psl;

use function chdir;
use function str_contains;

/**
 * Changes the current working directory to the specified path.
 *
 * @throws Psl\Exception\InvariantViolationException If the operation fails, or $directory contains the NUL character `\0`.
 *
 * @api
 */
function set_current_dir(string $directory): void
{
    if (str_contains($directory, "\0") || !@chdir($directory)) {
        Psl\invariant_violation('Unable to change directory');
    }
}
