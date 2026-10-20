<?php

declare(strict_types=1);

namespace Psl\Filesystem\Internal;

use Closure;

use function restore_error_handler;
use function set_error_handler;
use function strpos;
use function substr;

/**
 * Run $fun, capturing the last PHP warning it emits instead of reporting it.
 *
 * The captured message is stripped of PHP's "function(arguments): " prefix, e.g.
 * "unlink(/tmp/foo): Permission denied" becomes "Permission denied", as callers
 * already name the operation and the path in their own exception messages.
 *
 * @template T
 *
 * @param (Closure(): T) $fun
 *
 * @return array{0: T, 1: ?string}
 *
 * @internal
 */
function box(Closure $fun): array
{
    $lastMessage = null;
    set_error_handler(static function (int $_, string $message) use (&$lastMessage): void {
        $lastMessage = $message;
    });

    try {
        $value = $fun();
    } finally {
        restore_error_handler();
    }

    if (null !== $lastMessage) {
        $position = strpos($lastMessage, '): ');
        if (false !== $position) {
            $lastMessage = substr($lastMessage, $position + 3);
        }
    }

    return [$value, $lastMessage];
}
