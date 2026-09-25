# Encoding

The `Encoding` component provides functions for encoding and decoding binary data to and from text-safe representations. It supports Base64 (with multiple variants) and hexadecimal encoding, making it straightforward to prepare binary data for transport over text-based protocols like HTTP, email, or JSON.

## Base64

### Encoding and Decoding

```php
use Psl\Encoding\Base64;

$encoded = Base64\encode('Hello, World!');
// 'SGVsbG8sIFdvcmxkIQ=='

$decoded = Base64\decode($encoded);

// 'Hello, World!'
```

### URL-Safe Variant

Standard Base64 uses `+` and `/`, which conflict with URLs. The `UrlSafe` variant replaces them with `-` and `_`:

```php
use Psl\Encoding\Base64;
use Psl\SecureRandom;

// Standard encoding may produce URL-unfriendly characters
$binaryToken = SecureRandom\bytes(8);
$token = Base64\encode($binaryToken);

// URL-safe encoding is better for query parameters, filenames, and tokens
$token = Base64\encode($binaryToken, Base64\Variant::UrlSafe);
$original = Base64\decode($token, Base64\Variant::UrlSafe);
```

The `Variant` enum supports `Standard`, `UrlSafe`, `DotSlash`, `DotSlashOrdered`, and `Mime` for specialized use cases.

The `Mime` variant uses the standard alphabet but wraps output at 76 characters with CRLF line endings per RFC 2045, and strips whitespace on decode.

### Padding Control

Both `encode()` and `decode()` accept a padding parameter. You can strip padding on encode and decode without it:

```php
use Psl\Encoding\Base64;

$encoded = Base64\encode('Hello!', padding: false);
// 'SGVsbG8h' (no trailing '=')

$decoded = Base64\decode($encoded, explicitPadding: false);

// 'Hello!'
```

### Error Handling

Decoding invalid input throws typed exceptions rather than returning `false`:

```php
use Psl\Encoding\Base64;
use Psl\Encoding\Exception;

try {
    Base64\decode('not-valid-base64!');
} catch (Exception\RangeException $e) {
    echo $e->getMessage() . "\n";
}
```

## Hex

Hexadecimal encoding converts each byte into two hex characters. This is useful for displaying binary data like hashes, checksums, or cryptographic keys in a human-readable form.

```php
use Psl\Encoding\Exception;
use Psl\Encoding\Hex;

$hex = Hex\encode('Hello');
// '48656c6c6f'

$binary = Hex\decode('48656c6c6f');
// 'Hello'

// Invalid hex throws immediately
try {
    Hex\decode('xyz');
} catch (Exception\RangeException $e) {
    echo $e->getMessage() . "\n";
}

// Odd-length strings are rejected
try {
    Hex\decode('abc');
} catch (Exception\RangeException $e) {
    echo $e->getMessage() . "\n";
}
```

## Quoted-Printable

Quoted-printable encoding represents 8-bit data using only printable ASCII characters, as defined by RFC 2045 §6.7. It is commonly used in email (MIME) to encode content that is mostly ASCII with occasional special characters. Unlike Base64, quoted-printable keeps readable text readable.

```php
use Psl\Encoding\QuotedPrintable;
use Psl\IO;

$encoded = QuotedPrintable\encode("Hello =World!\r\nLine 2 with trailing space ");
IO\write_line('Encoded: %s', $encoded);

$decoded = QuotedPrintable\decode($encoded);
IO\write_line('Decoded: %s', $decoded);

// Custom line length
$short = QuotedPrintable\encode(str_repeat('A', 50), maxLineLength: 30);
IO\write_line('Short lines: %s', $short);

// Custom line ending
$unix = QuotedPrintable\encode("line1\r\nline2", lineEnding: "\n");
IO\write_line('Unix endings: %s', $unix);
```

Both `encode()` and `encode_line()` accept optional `$max_line_length` (default 76) and `$line_ending` (default `"\r\n"`) parameters.

## Encoded-Word (RFC 2047)

Encoded-word encoding is used in MIME headers (Subject, From, etc.) to represent non-ASCII text. `encode()` automatically selects Q-encoding (for mostly ASCII text) or B-encoding (for mostly non-ASCII text) based on the proportion of non-printable bytes.

```php
use Psl\Encoding\EncodedWord;
use Psl\IO;

// Encode a non-ASCII string for use in a MIME header
$encoded = EncodedWord\encode("caf\xC3\xA9 latt\xC3\xA9 is great");
IO\write_line('Encoded: %s', $encoded);

// Decode encoded-words back to UTF-8
$decoded = EncodedWord\decode($encoded);
IO\write_line('Decoded: %s', $decoded);

// Decode a B-encoded header
$subject = EncodedWord\decode('=?utf-8?B?Y2Fmw6k=?=');
IO\write_line('Subject: %s', $subject);

// Decode a Q-encoded header with underscores as spaces
$from = EncodedWord\decode('=?utf-8?Q?John_Doe?=');
IO\write_line('From: %s', $from);

// Plain ASCII passes through unchanged
$plain = EncodedWord\encode('Hello, World!');
IO\write_line('Plain: %s', $plain);
```

`encode()` accepts an optional `$charset` parameter of type `Psl\Str\Encoding` (default `Encoding::Utf8`). `decode()` handles charset conversion automatically, and per RFC 2047 §6.2, whitespace between adjacent encoded-words is removed.

## Streaming IO Handles

Each encoding namespace (Base64, Hex, QuotedPrintable) provides four IO handle decorators that transparently encode or decode data as it flows through:

- **`EncodingReadHandle`** wraps a raw readable handle; read() returns encoded data
- **`DecodingReadHandle`** wraps an encoded readable handle; read() returns decoded data
- **`EncodingWriteHandle`** accepts raw data via write(); encodes and writes to the inner handle
- **`DecodingWriteHandle`** accepts encoded data via write(); decodes and writes to the inner handle

Write handles have a `flush()` method to encode/decode any remaining buffered data.

This bridges `Psl\IO` with `Psl\Encoding`, allowing you to compose encoding into any IO pipeline:

```php
use Psl\Encoding\Base64;
use Psl\IO;

// Encode: wrap a raw handle, read() returns base64-encoded output
$raw = new IO\MemoryHandle('Hello, World!');
$encoder = new Base64\EncodingReadHandle($raw);
$encoded = $encoder->readAll();
IO\write_line('Encoded: %s', $encoded);

// Decode: wrap an encoded handle, read() returns raw bytes
$encodedHandle = new IO\MemoryHandle($encoded);
$decoder = new Base64\DecodingReadHandle($encodedHandle);
$decoded = $decoder->readAll();
IO\write_line('Decoded: %s', $decoded);

// Write handles work the other direction
$output = new IO\MemoryHandle();
$encodingWriter = new Base64\EncodingWriteHandle($output);
$encodingWriter->writeAll('Binary data here');
$encodingWriter->flush();

$output->seek(0);
IO\write_line('Written encoded: %s', $output->readAll());
```

Read handles also implement `BufferedReadHandleInterface`, providing `readByte()`, `readLine()`, `readUntil()`, and `readUntilBounded()` directly on the decoded/encoded output with zero wrapper overhead.

See [src/Psl/Encoding/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/encoding/src/Psl/Encoding/) for the full API.
