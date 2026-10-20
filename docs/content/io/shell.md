# Shell

The `Shell` component provides a single function, `Shell\execute()`, for running external commands and capturing their output. It handles argument escaping, error output management, and proper exception handling for failed commands.

For more advanced process management (streaming I/O, signals, non-blocking operation), see the `Process` component. `Shell` is the simpler choice when you just need to run a command and get the result.

## Basic Usage

@example('io/shell-execute.php')

## Handling Error Output

By default, stderr is discarded. The `ErrorOutputBehavior` enum controls how stderr is handled relative to stdout:

@example('io/shell-error-output.php')

## Cancellation

Pass a `CancellationTokenInterface` to cancel a running command. Use `TimeoutCancellationToken` to enforce a time limit:

@example('io/shell-timeout.php')

## Error Handling

`execute()` throws specific exceptions depending on what went wrong:

- **`FailedExecutionException`** -- the command exited with a non-zero status. Provides `getCommand()`, `getOutput()`, and `getErrorOutput()` for inspection.
- **`PossibleAttackException`** -- the command or an argument contains a NULL byte, indicating a potential injection attack.
- **`CancelledException`** -- the command was cancelled via the cancellation token.
- **`RuntimeException`** -- the working directory does not exist or contains a NULL byte, an environment variable name or value is invalid (for example, it contains a NULL byte), or the process could not be created.

@example('io/shell-error-handling.php')

See `src/Psl/Shell/` for the full API.
