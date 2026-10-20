# HPACK

The `HPACK` component implements RFC 7541 header compression for HTTP/2. It provides an `Encoder` and `Decoder` that compress and decompress HTTP header fields using static table lookups, dynamic table indexing, and Huffman coding.

## Encoding and Decoding

Both encoder and decoder are stateful - they maintain a dynamic table that evolves across multiple calls within the same HTTP/2 connection. Each connection should use its own instances.

@example('protocols/hpack-encode-decode.php')

## Sensitive Headers

Headers marked as sensitive (e.g. authorization, cookies) are encoded with the "never indexed" representation, preventing intermediary proxies from caching them in their dynamic tables.

@example('protocols/hpack-sensitive.php')

## Response Encoding

`encodeWithStatus()` is a convenience method for HTTP/2 response encoding. It prepends the `:status` pseudo-header and benefits from static table indexing for common status codes.

@example('protocols/hpack-response.php')

## Table Management

The dynamic table size can be adjusted at runtime via `resize()`. This is typically driven by the HTTP/2 SETTINGS_HEADER_TABLE_SIZE parameter negotiated between peers.

The maximum decompressed header list size is configurable via the constructor or `setMaxHeaderListSize()`. Headers that exceed this limit throw `HeaderListSizeException`.

If `encode()` or `encodeWithStatus()` rejects an oversized header list, it leaves the dynamic table and pending size updates unchanged. You can retry with a smaller list on the same encoder. A rejected call may consume its input iterator, so pass a fresh iterator when retrying.

@example('protocols/hpack-retry.php')

See `src/Psl/HPACK/` for the full API.
