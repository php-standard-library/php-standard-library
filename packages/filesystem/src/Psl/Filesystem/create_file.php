<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function max;
use function sprintf;
use function str_contains;
use function touch;

/**
 * Create the file specified by $filename.
 *
 * @param non-empty-string $filename
 * @param int|null $time The touch time as a Unix timestamp.
 *                       Defaults to the current system time.
 * @param int|null $accessTime The access time as a Unix timestamp.
 *                              Defaults to the current system time.
 *
 * @throws Exception\RuntimeException If unable to create the file.
 * @throws Exception\InvalidArgumentException If $filename contains a NUL byte.
 *
 * @api
 */
function create_file(string $filename, null|int $time = null, null|int $accessTime = null): void
{
    if (str_contains($filename, "\0")) {
        throw new Exception\InvalidArgumentException('$filename should not contain a NUL byte.');
    }

    if (null === $accessTime && null === $time) {
        $fun = static fn(): bool => touch($filename);
    } elseif (null === $accessTime) {
        $fun = static fn(): bool => touch($filename, $time);
    } else {
        $time ??= $accessTime;

        $fun = static fn(): bool => touch($filename, $time, max($accessTime, $time));
    }

    namespace\create_directory_for_file($filename);

    [$result, $error_message] = Internal\box($fun);
    // @codeCoverageIgnoreStart
    if (false === $result) {
        throw new Exception\RuntimeException(sprintf(
            'Failed to create file "%s": %s.',
            $filename,
            $error_message ?? 'internal error',
        ));
    }
    // @codeCoverageIgnoreEnd
}
