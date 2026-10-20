<?php

declare(strict_types=1);

namespace Psl\Shell;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Process;

use function implode;
use function pack;
use function str_contains;
use function strlen;

/**
 * Execute an external program.
 *
 * @param non-empty-string $command The command to execute.
 * @param list<string> $arguments The command arguments listed as separate entries.
 * @param null|non-empty-string $workingDirectory The initial working directory for the command.
 *                                                 This must be an absolute directory path, or null if you want to
 *                                                 use the default value ( the current directory )
 * @param array<string, string> $environment A dict with the environment variables for the command that
 *                                           will be run.
 *
 * @psalm-taint-sink shell $command
 *
 * @throws Exception\FailedExecutionException In case the command resulted in an exit code other than 0.
 * @throws Exception\PossibleAttackException In case the command being run is suspicious ( e.g: contains NULL byte ).
 * @throws Exception\RuntimeException In case $workingDirectory doesn't exist or contains a NULL byte, or unable to create a new process.
 * @throws CancelledException If the operation is cancelled.
 *
 * @api
 */
function execute(
    string $command,
    array $arguments = [],
    null|string $workingDirectory = null,
    array $environment = [],
    ErrorOutputBehavior $errorOutputBehavior = ErrorOutputBehavior::Discard,
    CancellationTokenInterface $cancellation = new NullCancellationToken(),
): string {
    if (str_contains($command, "\0")) {
        throw new Exception\PossibleAttackException('NULL byte detected.');
    }

    foreach ($arguments as $argument) {
        if (str_contains($argument, "\0")) {
            throw new Exception\PossibleAttackException('NULL byte detected.');
        }
    }

    $cmd = Process\Command::create($command)->withArguments($arguments)->withEnvironmentVariables($environment);

    if (null !== $workingDirectory) {
        $cmd = $cmd->withWorkingDirectory($workingDirectory);
    }

    try {
        $output = $cmd->output($cancellation);
    } catch (Process\Exception\RuntimeException $e) {
        throw new Exception\RuntimeException($e->getMessage(), 0, $e);
    }

    if (!$output->status->isSuccessful()) {
        $commandline = implode(' ', [$command, ...$arguments]);

        throw new Exception\FailedExecutionException(
            $commandline,
            $output->stdout,
            $output->stderr,
            $output->status->getCode(),
        );
    }

    if (ErrorOutputBehavior::Packed === $errorOutputBehavior) {
        $result = '';
        if ('' !== $output->stdout) {
            $result .= pack('C1N1', 1, strlen($output->stdout)) . $output->stdout;
        }

        if ('' !== $output->stderr) {
            $result .= pack('C1N1', 2, strlen($output->stderr)) . $output->stderr;
        }

        return $result;
    }

    return match ($errorOutputBehavior) {
        ErrorOutputBehavior::Prepend => $output->stderr . $output->stdout,
        ErrorOutputBehavior::Append => $output->stdout . $output->stderr,
        ErrorOutputBehavior::Replace => $output->stderr,
        ErrorOutputBehavior::Discard => $output->stdout,
    };
}
