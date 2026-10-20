<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function bin2hex;
use function chmod;
use function fclose;
use function fopen;
use function random_bytes;
use function realpath;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function umask;
use function unlink;

/**
 * Create a temporary file with permissions 0600.
 *
 * @param non-empty-string|null $directory The directory where the temporary file will be created.
 *                                         If none specified, the system default temporary directory will be used.
 * @param non-empty-string|null $prefix The prefix of the generated temporary filename.
 *
 * @throws Exception\RuntimeException If unable to create the file.
 * @throws Exception\NotFoundException If $directory is not found.
 * @throws Exception\NotDirectoryException If $directory is not a directory.
 * @throws Exception\InvalidArgumentException If $prefix contains a directory separator or a NUL byte.
 *
 * @return non-empty-string The absolute path to the temporary file.
 *
 * @api
 */
function create_temporary_file(null|string $directory = null, null|string $prefix = null): string
{
    if (null === $directory) {
        $dir = sys_get_temp_dir();
        $canonicalized = realpath($dir);
        $directory = false !== $canonicalized ? $canonicalized : $dir;
    }

    if (!namespace\exists($directory)) {
        throw Exception\NotFoundException::forDirectory($directory);
    }

    if (!namespace\is_directory($directory)) {
        throw Exception\NotDirectoryException::for($directory);
    }

    $separator = namespace\SEPARATOR;
    if (null !== $prefix) {
        if (str_contains($prefix, $separator)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '$prefix should not contain a directory separator ( "%s" ).',
                $separator,
            ));
        }

        if (str_contains($prefix, "\0")) {
            throw new Exception\InvalidArgumentException('$prefix should not contain a NUL byte.');
        }
    } else {
        $prefix = '';
    }

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $filename = $directory . $separator . $prefix . bin2hex(random_bytes(8));
        $previousMask = umask(0o077);
        try {
            [$handle, $errorMessage] = Internal\box(static fn() => fopen($filename, 'x'));
        } finally {
            umask($previousMask);
        }

        if ($handle !== false) {
            [$private, $errorMessage] = Internal\box(static fn(): bool => chmod($filename, 0o600));
            fclose($handle);
            if (!$private) {
                Internal\box(static fn(): bool => unlink($filename));

                throw new Exception\RuntimeException(sprintf(
                    'Failed to set temporary file permissions for "%s": %s.',
                    $filename,
                    $errorMessage ?? 'internal error',
                ));
            }

            return $filename;
        }

        if (!namespace\exists($filename) && !namespace\is_symbolic_link($filename)) {
            break;
        }
    }

    throw new Exception\RuntimeException(sprintf(
        'Failed to create temporary file in "%s": %s.',
        $directory,
        $errorMessage ?? 'internal error',
    ));
}
