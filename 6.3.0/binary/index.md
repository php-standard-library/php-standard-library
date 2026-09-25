# Binary

The `Binary` component provides typed functions and classes for encoding and decoding binary data, replacing PHP's cryptic `pack()` / `unpack()` format strings with a clear, type-safe API. It supports explicit endianness control via the `Endianness` enum.

## Why Use This?

PHP's `pack()` and `unpack()` rely on single-character format codes (`N`, `v`, `J`, `P`, etc.) that are hard to remember and easy to misuse. PSL's Binary component provides:

- **Named functions** like `encode_u32()` and `decode_i16()` instead of cryptic format strings.
- **Explicit endianness** via an enum instead of remembering which format code is big-endian vs little-endian.
- **Type-safe validation** that throws on overflow or underflow.
- **An immutable Writer** for building binary messages in memory.
- **A cursor-based Reader** for parsing binary data from a string.
- **Handle-based Writer and Reader** for streaming binary data directly to/from IO handles without buffering.

## One-Shot Functions

For encoding or decoding individual values, use the standalone functions. Each function validates its input and throws on error.

```php
use Psl\Binary;
use Psl\Binary\Endianness;
use Psl\IO;

// Encode an unsigned 16-bit integer in big-endian (network byte order)
$bytes = Binary\encode_u16(0x0102, Endianness::Big);
IO\write_line('u16 big-endian bytes: %s', bin2hex($bytes)); // 0102

// Decode it back
$value = Binary\decode_u16($bytes, Endianness::Big);
IO\write_line('Decoded u16: 0x%04X', $value); // 0x0102

// Encode a signed 32-bit integer in little-endian
$bytes = Binary\encode_i32(-1, Endianness::Little);
IO\write_line('i32 little-endian bytes: %s', bin2hex($bytes)); // ffffffff

// Decode it back
$value = Binary\decode_i32($bytes, Endianness::Little);
IO\write_line('Decoded i32: %d', $value); // -1

// Encode a 64-bit float
$bytes = Binary\encode_f64(3.14, Endianness::Big);
IO\write_line('f64 bytes: %s', bin2hex($bytes));
IO\write_line('Decoded f64: %f', Binary\decode_f64($bytes, Endianness::Big)); // 3.140000
```

## Writer

The `Writer` is an immutable builder for binary data. Each method returns a new instance with the appended bytes. Use this when you need the result as a string.

```php
use Psl\Binary\Endianness;
use Psl\Binary\Writer;
use Psl\IO;

// Build a binary protocol message: version(u8) + type(u16) + length(u32) + payload
// Writer::default() creates a new Writer with default settings (big-endian)
$message = Writer::default()
    ->u8(1) // protocol version
    ->u16(0x0042) // message type
    ->u32(5) // payload length
    ->bytes('Hello') // payload
    ->toString();

IO\write_line('Message hex: %s', bin2hex($message));
// 01004200000005 48656c6c6f

// The Writer is immutable -- each call returns a new instance
$base = new Writer(endianness: Endianness::Little);
$a = $base->u16(1)->u16(2);
$b = $base->u16(3)->u16(4);

IO\write_line('A: %s', bin2hex($a->toString())); // 01000200
IO\write_line('B: %s', bin2hex($b->toString())); // 03000400

// You can also mix endianness per call
$mixed = new Writer(endianness: Endianness::Big)
    ->u16(0x0102) // big-endian (default)
    ->u16(0x0304, Endianness::Little) // little-endian override
    ->toString();

IO\write_line('Mixed: %s', bin2hex($mixed)); // 01020403
```

## Reader

The `Reader` provides cursor-based sequential reading of binary data from a string. Each read advances the internal cursor.

```php
use Psl\Binary\Endianness;
use Psl\Binary\Reader;
use Psl\Binary\Writer;
use Psl\IO;

// Build a message, then parse it
$data = new Writer(endianness: Endianness::Big)
    ->u8(1) // version
    ->u16(0x0042) // type
    ->u32(5) // payload length
    ->bytes('Hello') // payload
    ->toString();

$reader = new Reader($data, Endianness::Big);

$version = $reader->u8();
$type = $reader->u16();
$length = $reader->u32();
$payload = $reader->bytes($length);

IO\write_line('Version: %d', $version); // 1
IO\write_line('Type: 0x%04X', $type); // 0x0042
IO\write_line('Length: %d', $length); // 5
IO\write_line('Payload: %s', $payload); // Hello

// Track cursor position
IO\write_line('Cursor: %d / %d', $reader->cursor(), $reader->length()); // 12 / 12
IO\write_line('Remaining: %d', $reader->remaining()); // 0
IO\write_line('Consumed: %s', $reader->isConsumed() ? 'yes' : 'no'); // yes
```

## Handle-Based IO

`HandleWriter` and `HandleReader` operate directly on `IO\WriteHandleInterface` and `IO\ReadHandleInterface`, writing and reading binary data without buffering entire messages in PHP memory. This is ideal for network protocols over TCP/Unix sockets.

```php
use Psl\Binary\Endianness;
use Psl\Binary\HandleReader;
use Psl\Binary\HandleWriter;
use Psl\IO;
use Psl\IO\MemoryHandle;

// HandleWriter writes directly to any IO\WriteHandleInterface.
// In production, this could be a TCP socket - here we use MemoryHandle for demo.
$handle = new MemoryHandle();

$writer = new HandleWriter($handle, Endianness::Big);
// version
$writer->u8(1);
// type
$writer->u16(0x0042);
// payload length
$writer->u32(5);
// payload
$writer->bytes('Hello');

IO\write_line('Wrote %d bytes to handle', strlen($handle->getBuffer()));

// Seek back to start so we can read what was written
$handle->seek(0);

// HandleReader reads directly from any IO\ReadHandleInterface
$reader = new HandleReader($handle, Endianness::Big);

$version = $reader->u8();
$type = $reader->u16();
$length = $reader->u32();
$payload = $reader->bytes($length);

IO\write_line('Version: %d', $version); // 1
IO\write_line('Type: 0x%04X', $type); // 0x0042
IO\write_line('Length: %d', $length); // 5
IO\write_line('Payload: %s', $payload); // Hello
```

## Length-Prefixed Bytes

All writers and readers support length-prefixed byte strings, a common pattern in binary protocols where the payload length is written before the payload itself. The prefix size determines the maximum payload length.

```php
use Psl\Binary\Reader;
use Psl\Binary\Writer;
use Psl\IO;

// Length-prefixed bytes: write the payload length as a typed integer,
// followed by the raw payload bytes, in a single method call.

$data = Writer::default()
    ->u8(1) // version
    ->u16(0x0042) // message type
    ->u32PrefixedBytes('Hello, PSL!') // u32 length prefix + payload
    ->u8PrefixedBytes('OK') // u8 length prefix + short status
    ->toString();

IO\write_line('Message hex: %s', bin2hex($data));

// Read it back
$reader = new Reader($data);

$version = $reader->u8();
$type = $reader->u16();
$payload = $reader->u32PrefixedBytes(); // reads u32 length, then that many bytes
$status = $reader->u8PrefixedBytes();

IO\write_line('Version: %d', $version); // 1
IO\write_line('Type: 0x%04X', $type); // 0x0042
IO\write_line('Payload: %s', $payload); // Hello, PSL!
IO\write_line('Status: %s', $status); // OK

// skip() advances the reader without returning the data
$data2 = Writer::default()->u32(0xDEAD)->u32PrefixedBytes('Important')->toString(); // header we want to skip

$reader2 = new Reader($data2);
$reader2->skip(4); // skip the 4-byte header
IO\write_line('Skipped to: %s', $reader2->u32PrefixedBytes()); // Important
```

## Implementing Custom Writers and Readers

The `WriterConvenienceMethodsTrait` and `ReaderConvenienceMethodsTrait` provide default implementations of `skip()` and the length-prefixed methods. When implementing `WriterInterface` or `ReaderInterface`, use these traits to avoid re-implementing the convenience methods:

```php
use Psl\Binary\WriterInterface;
use Psl\Binary\WriterConvenienceMethodsTrait;

final class MyCustomWriter implements WriterInterface
{
    use WriterConvenienceMethodsTrait;

    // Only implement the primitive methods: u8, u16, u32, u64,
    // i8, i16, i32, i64, f32, f64, bytes
    // The prefixed methods come from the trait for free.
}
```

## Supported Types

| Type | Size | Signed | Function Pair |
|------|------|--------|---------------|
| u8 | 1 byte | No | `encode_u8` / `decode_u8` |
| u16 | 2 bytes | No | `encode_u16` / `decode_u16` |
| u32 | 4 bytes | No | `encode_u32` / `decode_u32` |
| u64 | 8 bytes | No | `encode_u64` / `decode_u64` |
| i8 | 1 byte | Yes | `encode_i8` / `decode_i8` |
| i16 | 2 bytes | Yes | `encode_i16` / `decode_i16` |
| i32 | 4 bytes | Yes | `encode_i32` / `decode_i32` |
| i64 | 8 bytes | Yes | `encode_i64` / `decode_i64` |
| f32 | 4 bytes | N/A | `encode_f32` / `decode_f32` |
| f64 | 8 bytes | N/A | `encode_f64` / `decode_f64` |

See [src/Psl/Binary/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/binary/src/Psl/Binary/) for the full API.
