<?php

declare(strict_types=1);

namespace Psl\Async;

use Closure;
use Fiber;
use Revolt\EventLoop;

/**
 * Execute the given closure in an async context, then exit with returned exit code.
 *
 * If the closure returns an awaitable, the awaitable *MUST* resolve with an exit code. Once the closure
 * and its awaitable are done, the event loop runs until no enabled, referenced callback is left.
 *
 * Call it from {main} only: no fiber can run the event loop, and the loop's own callbacks run inside
 * one. The check happens before the closure runs. Failures of the closure and of its awaitable, and
 * whatever the event loop throws, such as Revolt's `UncaughtThrowable` for a callback failure,
 * propagate unchanged.
 *
 * @param (Closure(): int)|(Closure(): Awaitable<int>)|(Closure(): never)|(Closure(): Awaitable<never>) $closure
 *
 * @throws Exception\LogicException If called inside a fiber.
 *
 * @codeCoverageIgnore
 *
 * @api
 */
function main(Closure $closure): never
{
    if (null !== Fiber::getCurrent()) {
        throw new Exception\LogicException('Psl\Async\main() must be called from {main}, not inside a fiber.');
    }

    $result = $closure();
    if ($result instanceof Awaitable) {
        $result = $result->await();
    }

    EventLoop::run();

    exit($result);
}
