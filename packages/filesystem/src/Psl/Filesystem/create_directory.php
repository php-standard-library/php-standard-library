<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function mkdir;
use function sprintf;
use function str_contains;

/**
 * Create the directory specified by $directory.
 *
 * @param non-empty-string $directory
 *
 * @throws Exception\RuntimeException If unable to create the directory.
 * @throws Exception\InvalidArgumentException If $directory contains a NUL byte.
 *
 * @api
 */
function create_directory(string $directory, int $permissions = 0o777): void
{
    if (str_contains($directory, "\0")) {
        throw new Exception\InvalidArgumentException('$directory should not contain a NUL byte.');
    }

    if (namespace\is_directory($directory)) {
        return;
    }

    [$result, $error_message] = Internal\box(static fn(): bool => mkdir($directory, $permissions, true));

    // @codeCoverageIgnoreStart
    if (false === $result) {
        throw new Exception\RuntimeException(sprintf(
            'Failed to create directory "%s": %s.',
            $directory,
            $error_message ?? 'internal error',
        ));
    }
    // @codeCoverageIgnoreEnd
}
