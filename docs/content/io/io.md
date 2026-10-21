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

@example('io/io-write.php')

All output functions support `sprintf`-style formatting.

## Standard Handles

Three functions return the process-level I/O handles:

@example('io/io-standard-handles.php')

In non-CLI SAPIs, `input_handle()` reads from `php://input` and `output_handle()` writes to `php://output`. `error_handle()` returns `null` outside CLI.

## MemoryHandle

`MemoryHandle` is an in-memory buffer implementing read, write, seek, and close. It is useful for testing code that accepts handle interfaces without touching the filesystem or network.

@example('io/io-memory-handle.php')

## Reader

`Reader` wraps any `ReadHandleInterface` and implements `BufferedReadHandleInterface`, providing buffered, higher-level reading methods:

@example('io/io-reader.php')

### Bounded Reads

`Reader::readUntilBounded()` works like `readUntil()` but enforces a maximum byte limit. If the suffix is not found within `$max_bytes`, an `IO\Exception\OverflowException` is thrown. This prevents unbounded memory consumption when reading from untrusted sources -- for example, capping HTTP header lines to a safe size so a malicious client cannot exhaust memory by sending an endless line.

@example('io/io-reader-bounded.php')

### End of Data

When nothing is buffered and the end has not been seen yet, `Reader::reachedEndOfDataSource()` reads from the underlying handle to find out, waiting for data if necessary. Pass it a cancellation token to bound that wait. It returns `false` if that read brings no data without reaching the end, or fails with an I/O error, which a later read reports. `Reader::readAll()` uses the token it is given for that check too, and if it throws, whether it is cancelled, hits an I/O error or gets any other exception from the underlying handle, the bytes it already read stay buffered for the next call.

## Spool

`IO\spool()` creates a handle that writes to memory until a threshold is reached (default 2MB), then transparently spools to a temporary file on disk. This is useful when buffering data of unknown size without risking excessive memory usage.

@example('io/io-spool.php')

## Pipes

`IO\pipe()` creates a connected pair of handles: anything written to the write end can be read from the read end.

@example('io/io-pipe.php')

## Copying and Streaming

`IO\copy()` reads from one handle and writes to another until EOF. If the writer implements `BufferedWriteHandleInterface`, it is flushed after all data has been written:

@example('io/io-copy.php')

For control over the read chunk size, use `IO\copy_chunked()`:

@example('io/io-copy-chunked.php')

Both functions have bidirectional variants -- `IO\copy_bidirectional()` and `IO\copy_bidirectional_chunked()` -- that copy data in both directions concurrently between two read-write handles. These are useful for building proxies and tunnels.

`IO\streaming()` multiplexes reads from several stream handles concurrently, yielding chunks as they arrive. This is useful for reading interleaved process output:

@example('io/io-streaming.php')

## Handle Decorators

PSL provides several decorator handles that wrap an existing handle and add behavior.

### IterableReadHandle

`IterableReadHandle` wraps an `iterable<string>` (array, generator, or any iterable) as a streaming `ReadHandleInterface`. The iterator is advanced lazily on each `read()` call, so the entire content is never buffered in memory.

@example('io/io-iterable-read-handle.php')

### ConcatReadHandle

`ConcatReadHandle` reads from two handles in sequence. It delegates to the first handle until EOF, then transparently switches to the second.

@example('io/io-concat-read-handle.php')

### TruncatedReadHandle

`TruncatedReadHandle` reads up to N bytes from the underlying handle. Once the limit is reached, it silently reports EOF regardless of whether more data is available. Data beyond the limit is neither consumed nor validated.

@example('io/io-truncated-read-handle.php')

### BoundedReadHandle

`BoundedReadHandle` reads up to N bytes from the underlying handle. Unlike `TruncatedReadHandle`, it peeks past the limit to check for overflow. If the underlying handle has more data, an `IO\Exception\LimitExceededException` is thrown; it is an `IO\Exception\RuntimeException` whose `$limit` property holds the limit. Once an overflow has been seen, every later read throws the same exception without reading from the underlying handle again. Use this when exceeding the limit is a violation (e.g., HTTP response body size limits).

At the limit, `read()` waits for the next byte before deciding, and `tryRead()` returns an empty string while nothing is available yet. The handle reports EOF only when the underlying handle does, so bytes that arrive later still count as overflow.

@example('io/io-bounded-read-handle.php')

### FixedLengthReadHandle

`FixedLengthReadHandle` reads exactly N bytes from the underlying handle. If the handle reaches EOF before N bytes are consumed, an `IO\Exception\RuntimeException` is thrown. Use this when the data source promises a specific length (e.g., HTTP Content-Length bodies, fixed-size protocol frames).

@example('io/io-fixed-length-read-handle.php')

### JoinedReadWriteHandle

`JoinedReadWriteHandle` combines a `ReadHandleInterface` and a `WriteHandleInterface` into a single bidirectional handle. All read operations delegate to the reader, all write operations delegate to the writer. It implements `BufferedWriteHandleInterface`, so calling `flush()` delegates to the underlying writer if it also implements `BufferedWriteHandleInterface`.

@example('io/io-joined-read-write-handle.php')

### TeeWriteHandle

`TeeWriteHandle` writes to two handles simultaneously. If the second handle is slower, data is buffered internally and drained on subsequent writes. It implements `BufferedWriteHandleInterface` -- call `flush()` to explicitly drain any pending data to the second handle. If a write to the second handle fails, the bytes it has not reported accepting stay pending, and a retried `flush()` writes only those. A write to a PSL pipe or socket handle that is cancelled, or fails, after part of its bytes went out reports none of them, so a `flush()` retried after a timeout on such a handle can repeat those bytes. Writes made concurrently from several fibers never lose or repeat bytes, but they are not serialized: when the second handle takes one write's bytes in several parts, another write's bytes can come between them, and the two handles then hold the bytes in different orders. This is useful for mirroring output to a log, computing a hash while streaming, or duplicating data to two destinations.

@example('io/io-tee-write-handle.php')

### Sink Handles

`SinkWriteHandle` discards all written data, like `/dev/null`. `SinkReadHandle` is a read handle that always reports EOF immediately -- unlike `new MemoryHandle('')` which only reports EOF after the first read attempt. `SinkReadWriteHandle` combines both: writes are discarded and reads always report EOF. All write sinks implement `BufferedWriteHandleInterface` with a no-op `flush()` for compatibility with code that expects flushable writers. These are useful as no-op handles in tests or when output must be consumed but can be discarded.

@example('io/io-sink-handle.php')

See `src/Psl/IO/` for the full API.
