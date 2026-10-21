<?php

declare(strict_types=1);

namespace Psl\Env;

use function chdir;
use function error_clear_last;
use function error_get_last;
use function sprintf;
use function str_contains;

/**
 * Changes the current working directory to the specified path.
 *
 * @throws Exception\RuntimeException If the operation fails, or $directory contains the NUL character `\0`.
 *
 * @api
 */
function set_current_dir(string $directory): void
{
    if (str_contains($directory, "\0")) {
        throw new Exception\RuntimeException('Unable to change directory: the path contains the NUL character.');
    }

    error_clear_last();
    if (!@chdir($directory)) {
        $error = error_get_last();

        throw new Exception\RuntimeException(sprintf(
            'Unable to change directory to "%s": %s',
            $directory,
            $error['message'] ?? 'internal error.',
        ));
    }
}
