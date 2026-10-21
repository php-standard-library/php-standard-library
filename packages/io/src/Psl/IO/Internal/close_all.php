<?php

declare(strict_types=1);

namespace Psl\IO\Internal;

use Psl\IO;

/**
 * Close each of the given handles that is closeable, once, even if closing an earlier one fails.
 *
 * A handle given more than once is closed once. Only the IO runtime category, the failure a close is documented to
 * throw, lets the remaining handles be closed; the first such failure is rethrown once every handle was tried. Any
 * other throwable is a defect and propagates at once.
 *
 * @throws IO\Exception\RuntimeException The first failure, once every handle was tried.
 *
 * @internal
 */
function close_all(IO\HandleInterface ...$handles): void
{
    $failure = null;
    $closed = [];
    foreach ($handles as $handle) {
        if (!$handle instanceof IO\CloseHandleInterface) {
            continue;
        }

        foreach ($closed as $done) {
            if ($done === $handle) {
                continue 2;
            }
        }

        $closed[] = $handle;

        try {
            $handle->close();
        } catch (IO\Exception\RuntimeException $e) {
            $failure ??= $e;
        }
    }

    if (null !== $failure) {
        throw $failure;
    }
}
