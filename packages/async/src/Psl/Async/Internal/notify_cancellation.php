<?php

declare(strict_types=1);

namespace Psl\Async\Internal;

use Closure;
use Psl\Async\Exception;
use Throwable;

use function count;

/**
 * Invoke every cancellation callback, then rethrow what the callbacks threw.
 *
 * A callback that throws does not keep the later callbacks from running: each one is invoked with the
 * exception, in the given order, before any failure is rethrown.
 *
 * @param array<string, (Closure(Exception\CancelledException): void)> $callbacks
 *
 * @throws Throwable The failure of the only callback that threw, unchanged.
 * @throws Exception\CompositeException The failures of the callbacks that threw, in callback order, if several did.
 *
 * @internal
 */
function notify_cancellation(array $callbacks, Exception\CancelledException $exception): void
{
    $failures = [];
    foreach ($callbacks as $callback) {
        try {
            $callback($exception);
        } catch (Throwable $failure) {
            $failures[] = $failure;
        }
    }

    if ([] === $failures) {
        return;
    }

    if (1 === count($failures)) {
        throw $failures[0];
    }

    throw new Exception\CompositeException($failures);
}
