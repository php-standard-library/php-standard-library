# Compression

The `Compression` component provides streaming compression and decompression abstractions for IO handles. It defines compressor and decompressor interfaces, and four handle decorators that transparently compress or decompress data as it flows through.

This component is an abstraction layer -- it does not include any compression algorithms. Concrete implementations (e.g. deflate, gzip, brotli, zstd) are provided by separate packages that implement `CompressorInterface` and `DecompressorInterface`.

## Interfaces

Two interfaces define the compression contract:

- **`CompressorInterface`** -- `push(string $data): string` to incrementally compress, `finish(): string` to finalize and reset
- **`DecompressorInterface`** -- `push(string $data): string` to incrementally decompress, `finish(): string` to finalize and reset

After `finish()`, the instance resets to initial state and can be reused for a new stream. Compression and decompression are inherently stateful -- a single instance must not be used concurrently across multiple fibers or streams.

## Readers

`CompressingReadHandle` and `DecompressingReadHandle` wrap a readable handle and transform data on read. Both implement `ReadHandleInterface`. Wrap with `IO\Reader` for buffered methods like `readLine()` and `readUntil()`.

Both accept an optional `$chunkSize` parameter (default 8192) controlling how many bytes are read from the inner handle per iteration. Increase it for large file compression, decrease it to reduce memory usage per stream.

```php
use Psl\Compression;
use Psl\IO;

// CompressingReadHandle wraps a readable handle and compresses data on read.
// DecompressingReadHandle does the reverse -- decompresses data on read.
// Both implement ReadHandleInterface. Wrap with IO\Reader for buffered methods.

$source = new IO\MemoryHandle('hello world');

/** @var Compression\CompressorInterface $compressor */
$handle = new Compression\CompressingReadHandle($source, $compressor);

$compressed = $handle->readAll();

IO\write_line('Compressed: %s', $compressed);
```

## Writers

`CompressingWriteHandle` and `DecompressingWriteHandle` wrap a writable handle and transform data on write. Both implement `BufferedWriteHandleInterface`. Call `flush()` to finalize the handle and write any remaining buffered data.

```php
use Psl\Async;
use Psl\Compression;
use Psl\DateTime\Duration;
use Psl\IO;

// CompressingWriteHandle wraps a writable handle and compresses data on write.
// DecompressingWriteHandle does the reverse -- decompresses data on write.
// Both implement BufferedWriteHandleInterface. Call flush() to finalize.

$output = new IO\MemoryHandle();

/** @var Compression\CompressorInterface $compressor */
$writer = new Compression\CompressingWriteHandle($output, $compressor);

$writer->writeAll('hello world');
$writer->flush(); // finalize the compression stream

// flush() accepts an optional CancellationTokenInterface for timeout control
$writer->flush(new Async\TimeoutCancellationToken(Duration::seconds(5)));

$output->seek(0);
IO\write_line('Result: %s', $output->readAll());
```

`flush()` accepts an optional `CancellationTokenInterface` parameter, so you can set a timeout on the finalization when writing to network handles.

See [src/Psl/Compression/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/compression/src/Psl/Compression/) for the full API.
