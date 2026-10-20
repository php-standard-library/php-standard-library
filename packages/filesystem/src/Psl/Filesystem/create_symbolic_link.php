<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function sprintf;
use function str_contains;
use function symlink;

/**
 * Create a symbolic link for $source.
 *
 * @param non-empty-string $source The file to create a symbolic link for.
 * @param non-empty-string $destination
 *
 * @throws Exception\RuntimeException If unable to create the symbolic link.
 * @throws Exception\NotFoundException If $source is not found.
 * @throws Exception\InvalidArgumentException If $destination contains a NUL byte.
 * @throws Exception\NotReadableException If $destination is a non-empty directory, and is non-readable {@see delete_directory()}.
 *
 * @mago-expect lint:no-empty-catch-clause
 *
 * @api
 */
function create_symbolic_link(string $source, string $destination): void
{
    if (!namespace\exists($source)) {
        throw Exception\NotFoundException::forNode($source);
    }

    if (str_contains($destination, "\0")) {
        throw new Exception\InvalidArgumentException('$destination should not contain a NUL byte.');
    }

    namespace\create_directory_for_file($destination);

    try {
        if (namespace\read_symbolic_link($destination) === $source) {
            return;
        }
    } catch (Exception\NotSymbolicLinkException) {
        try {
            namespace\delete_directory($destination, true);
        } catch (Exception\NotDirectoryException) {
            namespace\delete_file($destination);
        }
    } catch (Exception\NotFoundException) {
    }

    [$result, $error_message] = Internal\box(static fn(): bool => symlink($source, $destination));
    // @codeCoverageIgnoreStart
    if (false === $result) {
        throw new Exception\RuntimeException(sprintf(
            'Failed to create symbolic link "%s" from "%s": %s.',
            $destination,
            $source,
            $error_message ?? 'internal error',
        ));
    }
    // @codeCoverageIgnoreEnd
}
