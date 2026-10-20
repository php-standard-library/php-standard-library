# Process

The `Process` component provides a typed, non-blocking API for spawning and managing child processes, built on top of PSL's IO system.

It serves as an alternative to `proc_*`, `symfony/process`, and `amphp/process`, with an API inspired by Rust's `std::process` module adapted to PSL conventions.

## Usage

@example('io/process-output.php')

### Command Construction

`Command` is an immutable builder. All `with*()` methods return a new instance.

- `Command::create(string $program)` -- executes a program directly, bypassing the shell. Prevents shell injection and is the recommended approach.
- `Command::shell(string $command)` -- interpreted by the system shell (`/bin/sh -c` on Unix, `cmd.exe` on Windows). Allows pipes, globbing, and variable expansion.

### Stdio Configuration

`Stdio` controls what happens with each standard I/O stream:

- `Stdio::piped()` -- pipe between parent and child (default for stdout/stderr)
- `Stdio::inherit()` -- child inherits the parent's descriptor
- `Stdio::null()` -- attach to `/dev/null` (default for stdin)
- `Stdio::tty()` -- connect directly to the terminal, useful for interactive programs (Unix only)
- `Stdio::fromStreamHandle($handle)` -- use an existing stream handle

## Examples

### Collect Output

@example('io/process-collect-output.php')

### Check Exit Status

@example('io/process-exit-status.php')

### Write to Stdin

@example('io/process-stdin.php')

### Shell Commands

@example('io/process-shell.php')

### Cancellation

@example('io/process-timeout.php')

### Signals

@example('io/process-signals.php')

### Environment Variables

The child process starts with a copy of the parent's environment. `withEnvironmentVariable()` and `withEnvironmentVariables()` add or override variables, `withoutEnvironmentVariable()` removes a variable even when it is inherited, and `withClearedEnvironment()` starts the child from an empty environment, so only variables set afterwards are passed to it.

@example('io/process-environment.php')

### Non-Blocking Poll

@example('io/process-poll.php')

See `src/Psl/Process/` for the full API.
