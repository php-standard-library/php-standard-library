<?php

declare(strict_types=1);

namespace Psl\Filesystem;

use function link;
use function sprintf;
use function str_contains;

/**
 * Create a hard link for $source.
 *
 * @param non-empty-string $source The file to create a hard link for.
 * @param non-empty-string $destination
 *
 * @throws Exception\RuntimeException If unable to create the hard link.
 * @throws Exception\NotFoundException If $source does not exist.
 * @throws Exception\NotFileException If $source is not a file.
 * @throws Exception\InvalidArgumentException If $destination contains a NUL byte.
 * @throws Exception\NotReadableException If $destination is a non-empty directory, and is non-readable {@see delete_directory()}.
 *
 * @api
 */
function create_hard_link(string $source, string $destination): void
{
    if (!namespace\exists($source)) {
        throw Exception\NotFoundException::forFile($source);
    }

    if (!namespace\is_file($source)) {
        throw Exception\NotFileException::for($source);
    }

    if (str_contains($destination, "\0")) {
        throw new Exception\InvalidArgumentException('$destination should not contain a NUL byte.');
    }

    if (namespace\exists($destination)) {
        if (namespace\get_inode($destination) === namespace\get_inode($source)) {
            // already exists.
            return;
        }

        try {
            namespace\delete_directory($destination, true);
        } catch (Exception\NotDirectoryException) {
            namespace\delete_file($destination);
        }
    } else {
        namespace\create_directory_for_file($destination);
    }

    [$result, $error_message] = Internal\box(static fn(): bool => link($source, $destination));
    // @codeCoverageIgnoreStart
    if (false === $result) {
        throw new Exception\RuntimeException(sprintf(
            'Failed to create hard link "%s" from "%s": %s.',
            $destination,
            $source,
            $error_message ?? 'internal error',
        ));
    }
    // @codeCoverageIgnoreEnd
}
