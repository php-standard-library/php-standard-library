# HPACK

The `HPACK` component implements RFC 7541 header compression for HTTP/2. It provides an `Encoder` and `Decoder` that compress and decompress HTTP header fields using static table lookups, dynamic table indexing, and Huffman coding.

## Encoding and Decoding

Both encoder and decoder are stateful - they maintain a dynamic table that evolves across multiple calls within the same HTTP/2 connection. Each connection should use its own instances.

```php
use Psl\HPACK;
use Psl\IO;

// Encode request headers
$encoder = new HPACK\Encoder();
$encoded = $encoder->encode([
    new HPACK\Header(':method', 'GET'),
    new HPACK\Header(':path', '/'),
    new HPACK\Header(':scheme', 'https'),
    new HPACK\Header(':authority', 'example.com'),
    new HPACK\Header('accept', 'text/html'),
]);

IO\write_line('Encoded %d bytes', strlen($encoded));

// Decode on the other side
$decoder = new HPACK\Decoder();
$headers = $decoder->decode($encoded);

foreach ($headers as $header) {
    IO\write_line('%s: %s', $header->name, $header->value);
}
```

## Sensitive Headers

Headers marked as sensitive (e.g. authorization, cookies) are encoded with the "never indexed" representation, preventing intermediary proxies from caching them in their dynamic tables.

```php
use Psl\HPACK;
use Psl\IO;

// Sensitive headers are encoded with "never indexed" representation,
// preventing intermediary proxies from caching them in their dynamic tables.
$encoder = new HPACK\Encoder();
$encoded = $encoder->encode([
    new HPACK\Header(':method', 'POST'),
    new HPACK\Header(':path', '/login'),
    new HPACK\Header(':scheme', 'https'),
    new HPACK\Header(':authority', 'example.com'),
    new HPACK\Header('authorization', 'Bearer secret-token', sensitive: true),
    new HPACK\Header('cookie', 'session=abc123', sensitive: true),
]);

$decoder = new HPACK\Decoder();
$headers = $decoder->decode($encoded);

foreach ($headers as $header) {
    $flag = $header->sensitive ? ' [sensitive]' : '';
    IO\write_line('%s: %s%s', $header->name, $header->value, $flag);
}
```

## Response Encoding

`encodeWithStatus()` is a convenience method for HTTP/2 response encoding. It prepends the `:status` pseudo-header and benefits from static table indexing for common status codes.

```php
use Psl\HPACK;
use Psl\IO;

// encodeWithStatus() is optimized for HTTP/2 response encoding.
// It prepends the :status pseudo-header in one pass without
// allocating an intermediate array.
$encoder = new HPACK\Encoder();
$encoded = $encoder->encodeWithStatus('200', [
    new HPACK\Header('content-type', 'application/json'),
    new HPACK\Header('cache-control', 'no-cache'),
]);

$decoder = new HPACK\Decoder();
$headers = $decoder->decode($encoded);

foreach ($headers as $header) {
    IO\write_line('%s: %s', $header->name, $header->value);
}

// Output:
// :status: 200
// content-type: application/json
// cache-control: no-cache
```

## Table Management

The dynamic table size can be adjusted at runtime via `resize()`. This is typically driven by the HTTP/2 SETTINGS_HEADER_TABLE_SIZE parameter negotiated between peers.

`Decoder::resize()` changes the allowed table-size limit. Raising that limit leaves the peer's chosen size unchanged until the peer sends an HPACK table-size update. Lowering the limit evicts entries only if the chosen size exceeds it.

```php
use Psl\HPACK;
use Psl\IO;

$encoder = new HPACK\Encoder();
$decoder = new HPACK\Decoder();

// Allow the peer to select a larger table.
$decoder->resize(8192);

// The encoder selects that size and sends its update in the next block.
$encoder->resize(8192);
$encoded = $encoder->encode([new HPACK\Header(':method', 'GET')]);

foreach ($decoder->decode($encoded) as $header) {
    IO\write_line('%s: %s', $header->name, $header->value);
}
```

The maximum decompressed header list size is configurable via the constructor or `setMaxHeaderListSize()`. Headers that exceed this limit throw `HeaderListSizeException`.

If `encode()` or `encodeWithStatus()` rejects an oversized header list, it leaves the dynamic table and pending size updates unchanged. You can retry with a smaller list on the same encoder. A rejected call may consume its input iterator, so pass a fresh iterator when retrying.

```php
use Psl\HPACK;
use Psl\IO;

$encoder = new HPACK\Encoder(maxHeaderListSize: 64);
$decoder = new HPACK\Decoder();

try {
    $encoded = $encoder->encode([
        new HPACK\Header(':method', 'GET'),
        new HPACK\Header('x-extra', 'optional'),
    ]);
} catch (HPACK\Exception\HeaderListSizeException) {
    $encoded = $encoder->encode([new HPACK\Header(':method', 'GET')]);
}

foreach ($decoder->decode($encoded) as $header) {
    IO\write_line('%s: %s', $header->name, $header->value);
}
```

See [src/Psl/HPACK/](https://github.com/php-standard-library/php-standard-library/tree/6.4.2/packages/hpack/src/Psl/HPACK/) for the full API.
