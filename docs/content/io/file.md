# File

The `File` component provides typed file handles for reading and writing files. It builds on the `IO` component's handle interfaces, adding file-specific concerns like write modes and advisory locking.

## Quick Read and Write

For common cases, use the convenience functions:

@example('io/file-read-write.php')

Both `File\read()` and `File\write()` automatically acquire a lock (shared for reads, exclusive for writes) and release it when done.

A path containing a NUL byte can never exist: opening it for reading throws `NotFoundException`, and opening it for writing throws `InvalidArgumentException`.

## Write Modes

When opening a file for writing, you choose a `WriteMode` that controls how the file pointer is positioned and whether the file must already exist:

| Mode | Behavior |
|------|----------|
| `OpenOrCreate` | Opens or creates the file. Pointer at the beginning. Existing content is preserved. **(default)** |
| `Truncate` | Opens or creates the file and truncates it to zero length. |
| `Append` | Opens or creates the file. All writes go to the end regardless of seek position. |
| `MustCreate` | Creates a new file. Throws if the file already exists. |

@example('io/file-write-modes.php')

## File Handles

For more control, open a file handle directly. Handles implement the `IO` handle interfaces and add `getPath()`, `getSize()`, and locking.

@example('io/file-handles.php')

## File Locking

File handles support advisory locking via `lock()` and `tryLock()`. The returned `Lock` object is released by calling `release()` or when it is garbage collected. Only `release()` reports a failure to release it, such as a handle that was closed first; a lock that is garbage collected does not throw.

- **`LockType::Shared`** -- Multiple processes can hold a shared lock simultaneously. Use for reads.
- **`LockType::Exclusive`** -- Only one process can hold an exclusive lock. Use for writes.

@example('io/file-locking.php')

`tryLock()` acquires the lock immediately or throws `AlreadyLockedException`:

@example('io/file-try-lock.php')

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

@example('io/file-io-integration.php')

See `src/Psl/File/` for the full API.
