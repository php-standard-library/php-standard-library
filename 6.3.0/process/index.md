# Process

The `Process` component provides a typed, non-blocking API for spawning and managing child processes, built on top of PSL's IO system.

It serves as an alternative to `proc_*`, `symfony/process`, and `amphp/process`, with an API inspired by Rust's `std::process` module adapted to PSL conventions.

## Usage

```php
use Psl\IO;
use Psl\Process\Command;

// Run a command and collect its output
$output = Command::create('echo')->withArguments(['Hello', 'from', 'process'])->output();

if ($output->status->isSuccessful()) {
    IO\write_line('Output: %s', trim($output->stdout));
}
```

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

```php
use Psl\IO;
use Psl\Process\Command;

$output = Command::create('php')->withArguments(['-r', 'echo "hello world";'])->output();

IO\write_line('stdout: %s', $output->stdout); // "hello world"
IO\write_line('stderr: %s', $output->stderr); // ""
```

### Check Exit Status

```php
use Psl\IO;
use Psl\Process\Command;

$status = Command::create('php')->withArguments(['-r', 'exit(42);'])->status();

IO\write_line('Successful: %s', $status->isSuccessful() ? 'true' : 'false'); // false
IO\write_line('Exit code: %d', $status->getCode()); // 42
```

### Write to Stdin

```php
use Psl\IO;
use Psl\Process\Command;
use Psl\Process\Stdio;

$child = Command::create('cat')->withStdin(Stdio::piped())->spawn();

$child->getStdin()->writeAll("hello\n");
$child->getStdin()->close();

$output = $child->getStdout()->readAll();
$child->wait();

IO\write_line('Read from child: %s', trim($output));
```

### Shell Commands

```php
use Psl\IO;
use Psl\Process\Command;

$output = Command::shell('echo hello && echo world')->output();

IO\write_line('stdout: %s', $output->stdout); // "hello\nworld\n"
```

### Cancellation

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Process\Command;

try {
    Command::create('sleep')
        ->withArgument('60')
        ->status(new Async\TimeoutCancellationToken(Duration::seconds(1)));
} catch (Async\Exception\CancelledException) {
    IO\write_line('Process was killed after timeout (as expected)');
}
```

### Signals

```php
use Psl\IO;
use Psl\Process\Command;
use Psl\Process\Signal;
use Psl\Process\Stdio;

$child = Command::create('sleep')->withArgument('60')->withStdout(Stdio::null())->withStderr(Stdio::null())->spawn();

// Allow the child process to start before signaling
usleep(50_000);

$child->signal(Signal::Terminate);
$status = $child->wait();

IO\write_line('Has been signaled: %s', $status->hasBeenSignaled() ? 'true' : 'false');
$signal = $status->getTerminationSignal();
IO\write_line('Termination signal: %s', $signal !== null ? $signal->name : 'none');
```

### Environment Variables

```php
use Psl\IO;
use Psl\Process\Command;

$output = Command::create('php')
    ->withArguments(['-r', 'echo getenv("APP_ENV");'])
    ->withEnvironmentVariable('APP_ENV', 'production')
    ->output();

IO\write_line('APP_ENV: %s', $output->stdout); // "production"
```

### Non-Blocking Poll

```php
use Psl\IO;
use Psl\Process\Command;
use Psl\Process\Stdio;

$child = Command::create('sleep')->withArgument('0.1')->withStdout(Stdio::null())->withStderr(Stdio::null())->spawn();

$status = $child->tryWait(); // null (still running)
IO\write_line('Still running: %s', $status === null ? 'true' : 'false');

// ... do other work ...

$status = $child->wait(); // blocks until done
IO\write_line('Finished with code: %d', $status->getCode());
```

See [src/Psl/Process/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/process/src/Psl/Process/) for the full API.
