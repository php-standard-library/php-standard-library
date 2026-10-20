<?php

declare(strict_types=1);

namespace Psl\Process;

/**
 * @api
 */
final readonly class ExitStatus
{
    /**
     * @internal
     */
    public function __construct(
        private int $code,
        private bool $signaled = false,
        private null|int $terminationSignal = null,
    ) {}

    /**
     * Returns whether the process exited successfully (exit code 0).
     */
    public function isSuccessful(): bool
    {
        return 0 === $this->code;
    }

    /**
     * Returns the exit code of the process.
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Returns whether the process was terminated by a signal.
     */
    public function hasBeenSignaled(): bool
    {
        return $this->signaled;
    }

    /**
     * Returns the signal that terminated the process, if any.
     */
    public function getTerminationSignal(): null|Signal
    {
        if (null === $this->terminationSignal) {
            return null;
        }

        return Internal\NativeSignal::fromNumber($this->terminationSignal);
    }
}
