# File

The `File` component provides typed file handles for reading and writing files. It builds on the `IO` component's handle interfaces, adding file-specific concerns like write modes and advisory locking.

## Quick Read and Write

For common cases, use the convenience functions:

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$tmp = Filesystem\create_temporary_file(prefix: 'psl_file_');

// Write to a file (creates it if it does not exist)
File\write($tmp, '{"key": "value"}');

// Read an entire file
$content = File\read($tmp);
IO\write_line('Full content: %s', $content);

// Read with offset and length
$header = File\read($tmp, offset: 0, length: 6);
IO\write_line('Header: %s', $header);

// Overwrite with truncation
File\write($tmp, 'New content', File\WriteMode::Truncate);
IO\write_line('After truncate: %s', File\read($tmp));

Filesystem\delete_file($tmp);
```

Both `File\read()` and `File\write()` automatically acquire a lock (shared for reads, exclusive for writes) and release it when done.

## Write Modes

When opening a file for writing, you choose a `WriteMode` that controls how the file pointer is positioned and whether the file must already exist:

| Mode | Behavior |
|------|----------|
| `OpenOrCreate` | Opens or creates the file. Pointer at the beginning. Existing content is preserved. **(default)** |
| `Truncate` | Opens or creates the file and truncates it to zero length. |
| `Append` | Opens or creates the file. All writes go to the end regardless of seek position. |
| `MustCreate` | Creates a new file. Throws if the file already exists. |

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$logFile = Filesystem\create_temporary_file(prefix: 'psl_log_');
$lockFile = sys_get_temp_dir() . '/psl-session-' . uniqid() . '.lock';

// Append a log line
File\write($logFile, "event occurred\n", File\WriteMode::Append);
File\write($logFile, "another event\n", File\WriteMode::Append);
IO\write_line('Log contents: %s', File\read($logFile));

// Ensure we are creating a fresh file
$id = 'session-abc123';
File\write($lockFile, $id, File\WriteMode::MustCreate);
IO\write_line('Lock file contents: %s', File\read($lockFile));

Filesystem\delete_file($logFile);
Filesystem\delete_file($lockFile);
```

## File Handles

For more control, open a file handle directly. Handles implement the `IO` handle interfaces and add `getPath()`, `getSize()`, and locking.

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;
use Psl\Str;

$csvFile = Filesystem\create_temporary_file(prefix: 'psl_csv_');
$outputFile = Filesystem\create_temporary_file(prefix: 'psl_out_');
$datFile = Filesystem\create_temporary_file(prefix: 'psl_dat_');

// Prepare CSV data
File\write($csvFile, "name,age\nAlice,30\nBob,25\n");

// Read-only
$handle = File\open_read_only($csvFile);
$all = $handle->readAll();
$handle->close();
IO\write_line('CSV data: %s', Str\trim_right($all));

// Write-only with truncation
$handle = File\open_write_only($outputFile, File\WriteMode::Truncate);
$handle->writeAll("line 1\nline 2\n");
$handle->close();
IO\write_line('Output: %s', Str\trim_right(File\read($outputFile)));

// Read-write
File\write($datFile, 'hello world');
$handle = File\open_read_write($datFile);
$existing = $handle->readAll();
$handle->seek(0);
$handle->writeAll(Str\uppercase($existing));
$handle->close();
IO\write_line('Uppercased: %s', File\read($datFile));

Filesystem\delete_file($csvFile);
Filesystem\delete_file($outputFile);
Filesystem\delete_file($datFile);
```

## File Locking

File handles support advisory locking via `lock()` and `tryLock()`. The returned `Lock` object is released by calling `release()` or when it is garbage collected.

- **`LockType::Shared`** -- Multiple processes can hold a shared lock simultaneously. Use for reads.
- **`LockType::Exclusive`** -- Only one process can hold an exclusive lock. Use for writes.

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$counterFile = Filesystem\create_temporary_file(prefix: 'psl_counter_');
File\write($counterFile, '0');

$handle = File\open_read_write($counterFile);
$lock = $handle->lock(File\LockType::Exclusive);

$count = (int) $handle->readAll();
$handle->seek(0);
$handle->writeAll((string) ($count + 1));

$lock->release();
$handle->close();

IO\write_line('Counter incremented to: %s', File\read($counterFile));

Filesystem\delete_file($counterFile);
```

`tryLock()` acquires the lock immediately or throws `AlreadyLockedException`:

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$tmp = Filesystem\create_temporary_file(prefix: 'psl_lock_');
$handle = File\open_read_write($tmp);

try {
    $lock = $handle->tryLock(File\LockType::Exclusive);
    IO\write_line('Lock acquired successfully');
    $lock->release();
} catch (File\Exception\AlreadyLockedException) {
    IO\write_line('Another process holds the lock');
}

$handle->close();
Filesystem\delete_file($tmp);
```

## Blocking Behavior in Async Code

All file operations are blocking: they block the current fiber until the operation completes. This is inherent to filesystem I/O; operating systems do not provide truly non-blocking file I/O the way they do for sockets.

In concurrent applications, this means a file read or write in one fiber will prevent other fibers from running until it finishes. For most workloads this is fine since file I/O is fast, but if you are doing heavy file I/O alongside latency-sensitive network operations, consider offloading file work to a separate process.

The [`amphp/file`](https://github.com/amphp/file) package does exactly this: it spawns a worker process and delegates all file operations to it, communicating over IPC. Because amphp uses the same RevoltPHP event loop as PSL's async component, it integrates seamlessly:

```php
use Amp\File;
use Psl\Async;

Async\main(static function(): int {
    // Non-blocking file read; other fibers can run while this waits
    $content = File\read('/path/to/large-file.dat');

    return 0;
});
```

## Integration with IO

Because file handles implement the `IO` interfaces, they work with any code that accepts `IO\ReadHandleInterface` or `IO\WriteHandleInterface`:

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$sourceFile = Filesystem\create_temporary_file(prefix: 'psl_src_');
$destFile = Filesystem\create_temporary_file(prefix: 'psl_dst_');

File\write($sourceFile, 'binary data to copy');

$source = File\open_read_only($sourceFile);
$dest = File\open_write_only($destFile, File\WriteMode::Truncate);
IO\copy($source, $dest);
$source->close();
$dest->close();

IO\write_line('Copied: %s', File\read($destFile));

Filesystem\delete_file($sourceFile);
Filesystem\delete_file($destFile);
```

See [src/Psl/File/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/file/src/Psl/File/) for the full API.
