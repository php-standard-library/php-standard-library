<?php

declare(strict_types=1);

namespace Psl\File;

use Closure;
use Psl\IO;

/**
 * @api
 */
final class Lock
{
    private bool $released = false;

    /**
     * @param (Closure(): void) $releaseCallback
     *
     * @internal use {@see HandleInterface::lock()} to create a lock.
     */
    public function __construct(
        public readonly LockType $type,
        private Closure $releaseCallback,
    ) {}

    /**
     * Release the lock.
     *
     * Releasing a lock that is already released does nothing.
     *
     * @throws IO\Exception\AlreadyClosedException If the handle was closed before the lock was released.
     * @throws Exception\RuntimeException If the lock could not be released.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        ($this->releaseCallback)();
        $this->released = true;
    }

    /**
     * Release the lock if it was not released yet.
     *
     * A failure to release it, such as a handle that was closed first, is not thrown: a destructor has no caller
     * to report it to (conventions E11). Call {@see release()} to find out whether releasing the lock worked.
     */
    public function __destruct()
    {
        try {
            $this->release();
        } catch (Exception\RuntimeException|IO\Exception\RuntimeException) {
            // @mago-expect lint:no-empty-catch-clause - A destructor cannot report an expected release failure (E11).
        }
    }
}
