# IO

The `IO` component provides handle-based I/O abstractions. Instead of reaching for global functions like `fread()` and `fwrite()`, you work with typed handle interfaces that make I/O composable, testable, and safe.

## Handle Interfaces

Handles are defined as narrow interfaces, each describing a single capability:

- **`ReadHandleInterface`** -- read bytes, check for EOF
- **`WriteHandleInterface`** -- write bytes
- **`SeekHandleInterface`** -- move the cursor position
- **`CloseHandleInterface`** -- explicitly close the handle, check if closed via `isClosed()`

A concrete handle implements whichever combination applies. For example, a file handle implements read, write, seek, and close, while a network socket implements read, write, and close but not seek.

### Buffered Interfaces

Two higher-level interfaces extend the base read and write interfaces for handles that buffer data internally:

- **`BufferedReadHandleInterface`** -- extends `ReadHandleInterface` with `readByte()`, `readLine()`, `readUntil()`, and `readUntilBounded()`. Implemented by `Reader`.
- **`BufferedWriteHandleInterface`** -- extends `WriteHandleInterface` with `flush()` for explicitly flushing buffered output. Useful for decorators like compression handles that accumulate data before writing.

## Quick Output

Convenience functions write directly to stdout or stderr:

```php
use Psl\IO;

$msg = 'file not found';

IO\write('Hello, %s!', 'world'); // stdout, no newline
IO\write_line('Count: %d', 42); // stdout + newline
IO\write_error('something went wrong'); // stderr
IO\write_error_line('Error: %s', $msg); // stderr + newline
```

All output functions support `sprintf`-style formatting.

## Standard Handles

Three functions return the process-level I/O handles:

```php
use Psl\IO;

$stdin = IO\input_handle(); // ReadHandleInterface  (STDIN in CLI)
$stdout = IO\output_handle(); // WriteHandleInterface (STDOUT in CLI)
$stderr = IO\error_handle(); // WriteHandleInterface (STDERR in CLI, null otherwise)
```

In non-CLI SAPIs, `input_handle()` reads from `php://input` and `output_handle()` writes to `php://output`. `error_handle()` returns `null` outside CLI.

## MemoryHandle

`MemoryHandle` is an in-memory buffer implementing read, write, seek, and close. It is useful for testing code that accepts handle interfaces without touching the filesystem or network.

```php
use Psl\IO;

$handle = new IO\MemoryHandle();
$handle->writeAll('Hello, World!');
$handle->seek(0);
$handle->readAll(); // 'Hello, World!'
$_ = $handle->getBuffer(); // 'Hello, World!' (always returns full buffer)

// Use MemoryHandle as a test double for any WriteHandleInterface
function render_report(IO\WriteHandleInterface $output): void
{
    $output->writeAll("Report\n");
}

$buffer = new IO\MemoryHandle();
render_report($buffer);
Psl\invariant($buffer->getBuffer() === "Report\n", 'Expected buffer to contain "Report\n"');
```

## Reader

`Reader` wraps any `ReadHandleInterface` and implements `BufferedReadHandleInterface`, providing buffered, higher-level reading methods:

```php
use Psl\IO;

$handle = new IO\MemoryHandle("line one\nline two\n\x00rest");
$reader = new IO\Reader($handle);

$line = $reader->readLine(); // read until newline: "line one"
$byte = $reader->readByte(); // read exactly one byte: "l"
$chunk = $reader->readFixedSize(7); // read exactly 7 bytes: "ine two"
$until = $reader->readUntil("\0"); // read until a delimiter (delimiter consumed but not returned): "\n"

IO\write_line('Line:  %s', $line ?? '<line not found>');
IO\write_line('Byte:  %s', $byte);
IO\write_line('Chunk: %s', $chunk);
IO\write_line('Until: %s', $until ?? '<suffix not found>');
```

### Bounded Reads

`Reader::readUntilBounded()` works like `readUntil()` but enforces a maximum byte limit. If the suffix is not found within `$max_bytes`, an `IO\Exception\OverflowException` is thrown. This prevents unbounded memory consumption when reading from untrusted sources -- for example, capping HTTP header lines to a safe size so a malicious client cannot exhaust memory by sending an endless line.

```php
use Psl\IO;

$handle = new IO\MemoryHandle("GET / HTTP/1.1\r\nHost: example.com\r\n\r\nbody");
$reader = new IO\Reader($handle);

// Read the request line, capped at 8192 bytes
$requestLine = $reader->readUntilBounded("\r\n", 8192);
IO\write_line('Request line: %s', $requestLine ?? '<not found>');

// Read a header line, capped at 4096 bytes
$header = $reader->readUntilBounded("\r\n", 4096);
IO\write_line('Header: %s', $header ?? '<not found>');

// If the line exceeds the limit, OverflowException is thrown:
$tinyHandle = new IO\MemoryHandle("this line is way too long\r\n");
$tinyReader = new IO\Reader($tinyHandle);

try {
    $tinyReader->readUntilBounded("\r\n", 5);
} catch (IO\Exception\OverflowException $e) {
    IO\write_line('Caught: %s', $e->getMessage());
}
```

## Spool

`IO\spool()` creates a handle that writes to memory until a threshold is reached (default 2MB), then transparently spools to a temporary file on disk. This is useful when buffering data of unknown size without risking excessive memory usage.

```php
use Psl\IO;
use Psl\Str;

// Writes to memory until 2MB, then spools to a temporary file on disk
$handle = IO\spool();

$handle->writeAll('Hello, World!');
$handle->seek(0);
$handle->readAll(); // 'Hello, World!'

$handle->close();

// Custom threshold: spool to disk after 64 bytes
$small = IO\spool(maxMemory: 64);
$small->writeAll(Str\repeat('x', 256)); // transparently written to disk
$small->seek(0);
$small->readAll(); // 256 bytes of 'x'

$small->close();
```

## Pipes

`IO\pipe()` creates a connected pair of handles: anything written to the write end can be read from the read end.

```php
use Psl\IO;

[$reader, $writer] = IO\pipe();
$writer->writeAll('hello');
$writer->close();

$result = $reader->readAll(); // 'hello'
$reader->close();

IO\write_line('Read from pipe: %s', $result);
```

## Copying and Streaming

`IO\copy()` reads from one handle and writes to another until EOF. If the writer implements `BufferedWriteHandleInterface`, it is flushed after all data has been written:

```php
use Psl\IO;

$source = new IO\MemoryHandle('data to copy');
$dest = new IO\MemoryHandle();
IO\copy($source, $dest);

IO\write_line('Copied: %s', $dest->getBuffer()); // 'data to copy'
```

For control over the read chunk size, use `IO\copy_chunked()`:

```php
use Psl\IO;

$source = new IO\MemoryHandle(str_repeat('x', 100_000));
$destination = new IO\MemoryHandle();

// Copy using 4 KB chunks instead of the default 8 KB
$bytesCopied = IO\copy_chunked($source, $destination, 4096);

$bytesCopied; // 100000
```

Both functions have bidirectional variants -- `IO\copy_bidirectional()` and `IO\copy_bidirectional_chunked()` -- that copy data in both directions concurrently between two read-write handles. These are useful for building proxies and tunnels.

`IO\streaming()` multiplexes reads from several stream handles concurrently, yielding chunks as they arrive. This is useful for reading interleaved process output:

```php
use Psl\IO;

// Create two pipes to simulate concurrent streams
[$outReader, $outWriter] = IO\pipe();
[$errReader, $errWriter] = IO\pipe();

// Write data to both pipes
$outWriter->writeAll('stdout output');
$outWriter->close();
$errWriter->writeAll('stderr output');
$errWriter->close();

foreach (IO\streaming(['out' => $outReader, 'err' => $errReader]) as $name => $chunk) {
    IO\write_line('[%s] %s', $name, $chunk);
}

$outReader->close();
$errReader->close();
```

## Handle Decorators

PSL provides several decorator handles that wrap an existing handle and add behavior.

### IterableReadHandle

`IterableReadHandle` wraps an `iterable<string>` (array, generator, or any iterable) as a streaming `ReadHandleInterface`. The iterator is advanced lazily on each `read()` call, so the entire content is never buffered in memory.

```php
use Psl\IO;

// Wrap a generator as a streaming read handle
$handle = new IO\IterableReadHandle(
    (function () {
        yield 'first chunk';
        yield 'second chunk';
        yield 'third chunk';
    })(),
);

$handle->read(); // 'first chunk'
$handle->read(); // 'second chunk'
$handle->read(); // 'third chunk'
$handle->read(); // '' (EOF)
$handle->reachedEndOfDataSource(); // true

// Also works with arrays
$handle = new IO\IterableReadHandle(['hello', ' ', 'world']);
$handle->readAll(); // 'hello world'
```

### ConcatReadHandle

`ConcatReadHandle` reads from two handles in sequence. It delegates to the first handle until EOF, then transparently switches to the second.

```php
use Psl\IO;

// Concatenate two handles into a single stream
$header = new IO\MemoryHandle("Header\n");
$body = new IO\MemoryHandle('Body content');

$combined = new IO\ConcatReadHandle($header, $body);
$combined->readAll(); // "Header\nBody content"
```

### TruncatedReadHandle

`TruncatedReadHandle` reads up to N bytes from the underlying handle. Once the limit is reached, it silently reports EOF regardless of whether more data is available. Data beyond the limit is neither consumed nor validated.

```php
use Psl\IO;

// Read at most 5 bytes, silently discard the rest
$inner = new IO\MemoryHandle('hello world');
$handle = new IO\TruncatedReadHandle($inner, 5);

$handle->readAll(); // 'hello'
$handle->reachedEndOfDataSource(); // true

// The underlying handle still has data, but the truncated handle doesn't care
$inner->tryRead(); // ' world'
```

### BoundedReadHandle

`BoundedReadHandle` reads up to N bytes from the underlying handle. Unlike `TruncatedReadHandle`, it peeks past the limit to check for overflow. If the underlying handle has more data, an `IO\Exception\RuntimeException` is thrown. Use this when exceeding the limit is a violation (e.g., HTTP response body size limits).

```php
use Psl\IO;

// Enforce a maximum of 5 bytes -- throw if the source has more
$inner = new IO\MemoryHandle('hello');
$handle = new IO\BoundedReadHandle($inner, 5);
$handle->readAll(); // 'hello' -- fits exactly, no error

// When the source exceeds the limit, an exception is thrown
$inner = new IO\MemoryHandle('hello world');
$handle = new IO\BoundedReadHandle($inner, 5);
$handle->read(5); // 'hello'

try {
    $handle->read(); // throws -- underlying handle has more data
} catch (IO\Exception\RuntimeException $e) {
    $e->getMessage(); // 'Response body exceeded the configured limit of 5 bytes.'
}
```

### FixedLengthReadHandle

`FixedLengthReadHandle` reads exactly N bytes from the underlying handle. If the handle reaches EOF before N bytes are consumed, an `IO\Exception\RuntimeException` is thrown. Use this when the data source promises a specific length (e.g., HTTP Content-Length bodies, fixed-size protocol frames).

```php
use Psl\IO;

// Read exactly 11 bytes
$inner = new IO\MemoryHandle('hello world');
$handle = new IO\FixedLengthReadHandle($inner, 11);
$handle->readAll(); // 'hello world'
$handle->reachedEndOfDataSource(); // true

// Premature EOF throws
$inner = new IO\MemoryHandle('hi');
$handle = new IO\FixedLengthReadHandle($inner, 10);

try {
    $handle->readAll(); // throws -- only 2 bytes available, expected 10
} catch (IO\Exception\RuntimeException $e) {
    $e->getMessage(); // 'Expected 10 bytes, but only 2 were available (premature EOF)'
}
```

### JoinedReadWriteHandle

`JoinedReadWriteHandle` combines a `ReadHandleInterface` and a `WriteHandleInterface` into a single bidirectional handle. All read operations delegate to the reader, all write operations delegate to the writer. It implements `BufferedWriteHandleInterface`, so calling `flush()` delegates to the underlying writer if it also implements `BufferedWriteHandleInterface`.

```php
use Psl\IO;

// Join separate read and write handles into one bidirectional handle
$reader = new IO\MemoryHandle('incoming data');
$writer = new IO\MemoryHandle();

$handle = new IO\JoinedReadWriteHandle($reader, $writer);

$handle->readAll(); // 'incoming data' (from reader)
$handle->writeAll('outgoing data'); // written to writer
$writer->seek(0);
$writer->readAll(); // 'outgoing data'
```

### TeeWriteHandle

`TeeWriteHandle` writes to two handles simultaneously. If the second handle is slower, data is buffered internally and drained on subsequent writes. It implements `BufferedWriteHandleInterface` -- call `flush()` to explicitly drain any pending data to the second handle. This is useful for mirroring output to a log, computing a hash while streaming, or duplicating data to two destinations.

```php
use Psl\IO;

// Write to two handles simultaneously -- useful for logging, hashing, or mirroring
$primary = new IO\MemoryHandle();
$mirror = new IO\MemoryHandle();

$tee = new IO\TeeWriteHandle($primary, $mirror);
$tee->writeAll('hello world');

$primary->seek(0);
$primary->readAll(); // 'hello world'

$mirror->seek(0);
$mirror->readAll(); // 'hello world'
```

### Sink Handles

`SinkWriteHandle` discards all written data, like `/dev/null`. `SinkReadHandle` is a read handle that always reports EOF immediately -- unlike `new MemoryHandle('')` which only reports EOF after the first read attempt. `SinkReadWriteHandle` combines both: writes are discarded and reads always report EOF. All write sinks implement `BufferedWriteHandleInterface` with a no-op `flush()` for compatibility with code that expects flushable writers. These are useful as no-op handles in tests or when output must be consumed but can be discarded.

```php
use Psl\IO;

// SinkWriteHandle discards everything -- like /dev/null
$sink = new IO\SinkWriteHandle();
$sink->writeAll('this goes nowhere');
$sink->writeAll('neither does this');

// SinkReadWriteHandle also supports reads, but always reports EOF
$sink = new IO\SinkReadWriteHandle();
$sink->writeAll('discarded');
$sink->read(); // '' (always empty)
$sink->reachedEndOfDataSource(); // true (always EOF)
```

See [src/Psl/IO/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/io/src/Psl/IO/) for the full API.
