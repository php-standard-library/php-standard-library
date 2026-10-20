<?php

declare(strict_types=1);

namespace Psl\Process;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function defined;
use function getcwd;
use function getenv;
use function is_dir;
use function is_resource;
use function proc_open;
use function str_contains;

use const PHP_OS_FAMILY;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * @api
 */
final readonly class Command
{
    /**
     * @param non-empty-string $program
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private function __construct(
        private string $program,
        private array $arguments,
        private array $environment,
        private null|string $workingDirectory,
        private Stdio $stdin,
        private Stdio $stdout,
        private Stdio $stderr,
        private bool $shell,
    ) {}

    /**
     * Create a new command for the given program.
     *
     * The program will be executed directly without a shell, preventing shell injection.
     * Arguments should be added via {@see withArgument()} or {@see withArguments()}.
     *
     * @param non-empty-string $program
     */
    public static function create(string $program): self
    {
        return new self(
            program: $program,
            arguments: [],
            environment: [],
            workingDirectory: null,
            stdin: Stdio::null(),
            stdout: Stdio::piped(),
            stderr: Stdio::piped(),
            shell: false,
        );
    }

    /**
     * Create a new command to be interpreted by the system shell.
     *
     * On Unix, this runs through `/bin/sh -c`. On Windows, through `cmd.exe`.
     * This allows shell features like pipes, globbing, and variable expansion.
     *
     * @param non-empty-string $command
     */
    public static function shell(string $command): self
    {
        return new self(
            program: $command,
            arguments: [],
            environment: [],
            workingDirectory: null,
            stdin: Stdio::null(),
            stdout: Stdio::piped(),
            stderr: Stdio::piped(),
            shell: true,
        );
    }

    /**
     * Adds an argument to pass to the program.
     */
    public function withArgument(string $argument): self
    {
        return new self(
            program: $this->program,
            arguments: [...$this->arguments, $argument],
            environment: $this->environment,
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Adds multiple arguments to pass to the program.
     *
     * @param list<string> $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self(
            program: $this->program,
            arguments: [...$this->arguments, ...$arguments],
            environment: $this->environment,
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Sets an environment variable for the child process.
     */
    public function withEnvironmentVariable(string $name, string $value): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: [...$this->environment, $name => $value],
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Sets multiple environment variables for the child process.
     *
     * @param array<string, string> $variables
     */
    public function withEnvironmentVariables(array $variables): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: [...$this->environment, ...$variables],
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Removes an environment variable for the child process.
     */
    public function withoutEnvironmentVariable(string $name): self
    {
        $environment = $this->environment;
        unset($environment[$name]);

        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: $environment,
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Clears all environment variables, so the child process inherits nothing.
     */
    public function withClearedEnvironment(): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: [],
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Sets the working directory for the child process.
     */
    public function withWorkingDirectory(string $directory): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: $this->environment,
            workingDirectory: $directory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Configures the stdin descriptor for the child process.
     */
    public function withStdin(Stdio $stdio): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: $this->environment,
            workingDirectory: $this->workingDirectory,
            stdin: $stdio,
            stdout: $this->stdout,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Configures the stdout descriptor for the child process.
     */
    public function withStdout(Stdio $stdio): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: $this->environment,
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $stdio,
            stderr: $this->stderr,
            shell: $this->shell,
        );
    }

    /**
     * Configures the stderr descriptor for the child process.
     */
    public function withStderr(Stdio $stdio): self
    {
        return new self(
            program: $this->program,
            arguments: $this->arguments,
            environment: $this->environment,
            workingDirectory: $this->workingDirectory,
            stdin: $this->stdin,
            stdout: $this->stdout,
            stderr: $stdio,
            shell: $this->shell,
        );
    }

    /**
     * @return non-empty-string
     */
    public function getProgram(): string
    {
        return $this->program;
    }

    /**
     * @return list<string>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, string>
     */
    public function getEnvironmentVariables(): array
    {
        return $this->environment;
    }

    public function getWorkingDirectory(): null|string
    {
        return $this->workingDirectory;
    }

    /**
     * Spawns the process and returns a child handle.
     *
     * @throws Exception\StartFailedException If the process could not be started.
     * @throws Exception\RuntimeException If the working directory does not exist or contains NULL bytes.
     */
    public function spawn(): ChildInterface
    {
        return $this->doSpawn($this->stdin, $this->stdout, $this->stderr);
    }

    /**
     * Spawns the process with piped stdout/stderr, collects all output, and waits for exit.
     *
     * Stdin is set to null. Stdout and stderr are forced to piped regardless of configuration.
     *
     * @throws Exception\StartFailedException If the process could not be started.
     * @throws Exception\RuntimeException If the working directory does not exist or contains NULL bytes.
     * @throws CancelledException If the operation is cancelled.
     */
    public function output(CancellationTokenInterface $cancellation = new NullCancellationToken()): Output
    {
        $child = $this->doSpawn(Stdio::null(), Stdio::piped(), Stdio::piped());

        return $child->waitWithOutput($cancellation);
    }

    /**
     * Spawns the process with null stdio and waits for exit, returning only the exit status.
     *
     * @throws Exception\StartFailedException If the process could not be started.
     * @throws Exception\RuntimeException If the working directory does not exist or contains NULL bytes.
     * @throws CancelledException If the operation is cancelled.
     */
    public function status(CancellationTokenInterface $cancellation = new NullCancellationToken()): ExitStatus
    {
        $child = $this->doSpawn(Stdio::null(), Stdio::null(), Stdio::null());

        return $child->wait($cancellation);
    }

    /**
     * @throws Exception\StartFailedException
     * @throws Exception\RuntimeException
     */
    private function doSpawn(Stdio $stdin, Stdio $stdout, Stdio $stderr): Internal\Child
    {
        if (str_contains($this->program, "\0")) {
            throw new Exception\RuntimeException('Command line contains NULL bytes.');
        }

        foreach ($this->arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw new Exception\RuntimeException('Command line contains NULL bytes.');
            }
        }

        if (null !== $this->workingDirectory && str_contains($this->workingDirectory, "\0")) {
            throw new Exception\RuntimeException('Working directory contains NULL bytes.');
        }

        $command = $this->buildCommand();

        $environment = [...getenv(), ...$this->environment];
        $cwd = getcwd();
        $workingDirectory = $this->workingDirectory ?? ($cwd !== false ? $cwd : '');
        if ('' === $workingDirectory || !is_dir($workingDirectory)) {
            throw new Exception\RuntimeException('Working directory does not exist.');
        }

        $descriptors = [
            0 => $this->buildDescriptor($stdin, 'r', 0),
            1 => $this->buildDescriptor($stdout, 'w', 1),
            2 => $this->buildDescriptor($stderr, 'w', 2),
        ];

        $options = [];
        // @codeCoverageIgnoreStart
        if (PHP_OS_FAMILY === 'Windows') {
            $options['blocking_pipes'] = false;

            if (!$this->shell) {
                // Safe mode uses the array form which bypasses the shell already,
                // but set this defensively to ensure cmd.exe is never involved.
                $options['bypass_shell'] = true;
            }
        }

        // @codeCoverageIgnoreEnd

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $workingDirectory, $environment, $options);
        // @codeCoverageIgnoreStart
        if (!is_resource($process)) {
            throw new Exception\StartFailedException('Failed to start the process.');
        }

        // @codeCoverageIgnoreEnd

        $stdinHandle = null;
        if ($stdin->isPiped() && isset($pipes[0])) {
            $stdinHandle = new IO\CloseWriteStreamHandle($pipes[0]);
        }

        $stdoutHandle = null;
        if ($stdout->isPiped() && isset($pipes[1])) {
            $stdoutHandle = new IO\CloseReadStreamHandle($pipes[1]);
        }

        $stderrHandle = null;
        if ($stderr->isPiped() && isset($pipes[2])) {
            $stderrHandle = new IO\CloseReadStreamHandle($pipes[2]);
        }

        return new Internal\Child($process, $stdinHandle, $stdoutHandle, $stderrHandle);
    }

    /**
     * Builds the command for proc_open.
     *
     * In safe mode, returns an array which lets PHP handle argument escaping
     * internally on all platforms. In shell mode, returns a string that PHP
     * passes through the system shell (/bin/sh on Unix, cmd.exe on Windows).
     *
     * @return list<string>|string
     */
    private function buildCommand(): array|string
    {
        if ($this->shell) {
            return $this->program;
        }

        return [$this->program, ...$this->arguments];
    }

    /**
     * @param 'r'|'w' $mode
     * @param 0|1|2 $fd
     *
     * @return array{0: 'pipe', 1: 'r'|'w'}|array{0: 'file', 1: non-empty-string, 2: 'r'|'w'}|object|resource
     */
    private function buildDescriptor(Stdio $stdio, string $mode, int $fd): mixed
    {
        if ($stdio->isPiped()) {
            return ['pipe', $mode];
        }

        if ($stdio->isTty()) {
            // @codeCoverageIgnoreStart
            if (PHP_OS_FAMILY === 'Windows') {
                throw new Exception\RuntimeException('TTY is not supported on Windows.');
            }

            // @codeCoverageIgnoreEnd

            return ['file', '/dev/tty', $mode];
        }

        if ($stdio->isInherit()) {
            return match ($fd) {
                0 => STDIN,
                1 => defined('STDOUT') ? STDOUT : ['file', 'php://stdout', 'w'],
                default => defined('STDERR') ? STDERR : ['file', 'php://stderr', 'w'],
            };
        }

        if ($stdio->isHandle()) {
            $handle = $stdio->getHandle();
            if (null !== $handle) {
                $stream = $handle->getStream();
                if (null !== $stream) {
                    return $stream;
                }
            }

            throw new Exception\RuntimeException('The stream handle is closed.');
        }

        // Null mode
        // @codeCoverageIgnoreStart
        if (PHP_OS_FAMILY === 'Windows') {
            return ['file', 'NUL', $mode];
        }

        // @codeCoverageIgnoreEnd

        return ['file', '/dev/null', $mode];
    }
}
