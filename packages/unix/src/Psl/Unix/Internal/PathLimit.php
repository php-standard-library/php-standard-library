<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use function str_starts_with;

use const PHP_OS_FAMILY;

/**
 * The longest Unix socket name that the system can hold.
 *
 * A socket name lives in the `sun_path` field of a `sockaddr_un`, whose size depends on the system. PHP cuts a longer
 * name to fit, and uses what is left (main/streams/xp_socket.c, `parse_unix_address`), so a name must be checked
 * before it reaches PHP.
 *
 * @internal
 */
final class PathLimit
{
    /**
     * The size of `sun_path` on Linux and Solaris.
     */
    private const int LINUX_SUN_PATH_SIZE = 108;

    /**
     * The size of `sun_path` on macOS and the BSDs.
     */
    private const int BSD_SUN_PATH_SIZE = 104;

    /**
     * The room a filesystem path needs for the NUL byte that ends it.
     */
    private const int TERMINATOR_SIZE = 1;

    /**
     * The most bytes the given name can have on this system, or null if PSL does not know the system's limit.
     *
     * A filesystem path needs room for the NUL byte that ends it. A name in the Linux abstract namespace starts with
     * a NUL byte instead, and fills the whole field.
     *
     * @param non-empty-string $path
     *
     * @return null|positive-int
     */
    public static function maximumLength(string $path): null|int
    {
        $size = match (PHP_OS_FAMILY) {
            'Linux', 'Solaris' => self::LINUX_SUN_PATH_SIZE,
            'Darwin', 'BSD' => self::BSD_SUN_PATH_SIZE,
            default => null,
        };

        if (null === $size || str_starts_with($path, "\0")) {
            return $size;
        }

        return $size - self::TERMINATOR_SIZE;
    }
}
