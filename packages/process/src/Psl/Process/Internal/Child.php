<?php

declare(strict_types=1);

namespace Psl\Process\Internal;

use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Process\ChildInterface;
use Psl\Process\Exception;
use Psl\Process\ExitStatus;
use Psl\Process\Output;
use Psl\Process\Signal;

use function proc_close;
use function proc_get_status;
use function proc_terminate;

use const PHP_OS_FAMILY;

/**
 * @internal
 */
final class Child implements ChildInterface
{
    private bool $exited = false;
    private null|ExitStatus $exitStatus = null;

    /**
     * The status reported by proc_get_status() when it first observed the process as exited.
     *
     * PHP reports a termination by signal only the first time proc_get_status() observes the
     * exit; later calls report the process as not signaled, so the status is kept here.
     *
     * @var null|array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}
     */
    private null|array $exitedStatus = null;

    /**
     * @param resource $process
     */
    public function __construct(
        private mixed $process,
        private null|IO\CloseWriteStreamHandle $stdin,
        private null|IO\CloseReadStreamHandle $stdout,
        private null|IO\CloseReadStreamHandle $stderr,
    ) {}

    #[Override]
    public function getProcessId(): int
    {
        $status = $this->getStatus();

        return (int) $status['pid'];
    }

    #[Override]
    public function isRunning(): bool
    {
        if ($this->exited) {
            return false;
        }

        $status = $this->getStatus();

        return $status['running'];
    }

    #[Override]
    public function getStdin(): IO\WriteHandleInterface&IO\CloseHandleInterface&IO\StreamHandleInterface
    {
        if (null === $this->stdin) {
            throw new Exception\StreamUnavailableException(
                'Stdin is not available. Configure it with Stdio::piped() to access it.',
            );
        }

        return $this->stdin;
    }

    #[Override]
    public function getStdout(): IO\ReadHandleInterface&IO\CloseHandleInterface&IO\StreamHandleInterface
    {
        if (null === $this->stdout) {
            throw new Exception\StreamUnavailableException(
                'Stdout is not available. Configure it with Stdio::piped() to access it.',
            );
        }

        return $this->stdout;
    }

    #[Override]
    public function getStderr(): IO\ReadHandleInterface&IO\CloseHandleInterface&IO\StreamHandleInterface
    {
        if (null === $this->stderr) {
            throw new Exception\StreamUnavailableException(
                'Stderr is not available. Configure it with Stdio::piped() to access it.',
            );
        }

        return $this->stderr;
    }

    #[Override]
    public function signal(Signal $signal): void
    {
        if (!$this->isRunning()) {
            return;
        }

        // @codeCoverageIgnoreStart
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows only supports SIGTERM via proc_terminate which calls TerminateProcess.
            proc_terminate($this->process);

            return;
        }

        $result = proc_terminate($this->process, NativeSignal::toNumber($signal));
        if (!$result) {
            throw new Exception\RuntimeException('Failed to send signal to the process.');
        }
        // @codeCoverageIgnoreEnd
    }

    #[Override]
    public function kill(): void
    {
        $this->signal(Signal::Kill);
    }

    #[Override]
    public function wait(CancellationTokenInterface $cancellation = new NullCancellationToken()): ExitStatus
    {
        if (null !== $this->exitStatus) {
            return $this->exitStatus;
        }

        // Close stdin to signal EOF to the child process.
        $this->stdin?->close();
        $this->stdin = null;

        // Close stdout/stderr to avoid deadlock; unread data is discarded.
        $this->stdout?->close();
        $this->stdout = null;
        $this->stderr?->close();
        $this->stderr = null;

        return $this->doWait($cancellation);
    }

    #[Override]
    public function waitWithOutput(CancellationTokenInterface $cancellation = new NullCancellationToken()): Output
    {
        if (null !== $this->exitStatus) {
            return new Output($this->exitStatus, '', '');
        }

        // Close stdin to signal EOF to the child process.
        $this->stdin?->close();
        $this->stdin = null;

        $stdoutContent = '';
        $stderrContent = '';

        // Read stdout and stderr concurrently using IO\streaming.
        $handles = [];
        if (null !== $this->stdout) {
            $handles[1] = $this->stdout;
        }

        if (null !== $this->stderr) {
            $handles[2] = $this->stderr;
        }

        if ([] !== $handles) {
            try {
                foreach (IO\streaming($handles, $cancellation) as $type => $chunk) {
                    if ('' === $chunk) {
                        continue;
                    }

                    if (1 === $type) {
                        $stdoutContent .= $chunk;
                        continue;
                    }

                    $stderrContent .= $chunk;
                }
            } catch (Async\Exception\CancelledException $e) {
                // Kill the process on cancellation before closing handles.
                $this->kill();
                $this->closeHandles();
                $this->close();

                throw $e;
            }
        }

        $this->closeHandles();

        $status = $this->doWait(new NullCancellationToken());

        return new Output($status, $stdoutContent, $stderrContent);
    }

    #[Override]
    public function tryWait(): null|ExitStatus
    {
        if (null !== $this->exitStatus) {
            return $this->exitStatus;
        }

        if ($this->isRunning()) {
            return null;
        }

        return $this->close();
    }

    private function doWait(CancellationTokenInterface $cancellation): ExitStatus
    {
        if (null !== $this->exitStatus) {
            return $this->exitStatus;
        }

        /** @var null|Async\Exception\CancelledException $cancelledException */
        $cancelledException = null;
        $subscription = null;
        if ($cancellation->cancellable) {
            $subscription = $cancellation->subscribe(static function (Async\Exception\CancelledException $exception) use (
                &$cancelledException,
            ): void {
                $cancelledException = $exception;
            });
        }

        try {
            while ($this->isRunning()) {
                if (null !== $cancelledException) {
                    $this->kill();
                    $this->close();

                    throw $cancelledException;
                }

                // Small delay between polls to avoid busy-looping and to give
                // the OS time to update process status. This is important on Windows
                // where proc_get_status() can briefly report running=true after the
                // process has actually exited.
                Async\sleep(Duration::milliseconds(5));
            }
        } finally {
            if (null !== $subscription) {
                $cancellation->unsubscribe($subscription);
            }
        }

        return $this->close();
    }

    /**
     * @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}
     */
    private function getStatus(): array
    {
        if (null !== $this->exitedStatus) {
            return $this->exitedStatus;
        }

        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->exitedStatus = $status;
        }

        return $status;
    }

    private function closeHandles(): void
    {
        $this->stdout?->close();
        $this->stdout = null;
        $this->stderr?->close();
        $this->stderr = null;
    }

    private function close(): ExitStatus
    {
        if (null !== $this->exitStatus) {
            return $this->exitStatus;
        }

        $this->exited = true;

        $info = $this->getStatus();
        $code = proc_close($this->process);

        // proc_get_status only returns the real exit code and termination signal on the
        // first call after exit, which getStatus() keeps. proc_close returns the exit code
        // too, but it's shifted on Unix. Use the info exitcode when available.
        $exitCode = -1 !== $info['exitcode'] ? $info['exitcode'] : $code;

        $signaled = $info['signaled'];
        $termSignal = $signaled ? $info['termsig'] : null;

        $this->exitStatus = new ExitStatus($exitCode, $signaled, $termSignal);

        return $this->exitStatus;
    }
}
