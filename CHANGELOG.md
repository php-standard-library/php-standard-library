# Changelog

## 6.3.0

### features

* feat(type): add `numeric` type - [#793](https://github.com/php-standard-library/php-standard-library/pull/793) by @gsteel

### fixes

- fix(http-client): validate all `Transfer-Encoding` response fields and reject invalid or unsupported transfer codings instead of falling back to `Content-Length` - [#806](https://github.com/php-standard-library/php-standard-library/pull/806)

## 6.2.1

### security

- fix(h2): validate `content-length` header against received DATA on server connections, preventing HTTP/2 request smuggling on `Psl\H2\ServerConnection` (GHSA-pw9p-jvrm-f7rm)

## 6.2.0

### features

- feat(either-or-both): introduce `EitherOrBoth` component - a three-variant disjoint union (`Left` / `Right` / `Both`) for values that may be present on either or both of two sides, inspired by Rust's `itertools::EitherOrBoth` and Haskell's `Data.These`. Primary use case: three-way diff of two collections (insert / delete / update events). Secondary: layered config merge, multi-source enrichment, dual-validation, snapshot comparison. Full `map` / `mapLeft` / `mapRight` / `mapAny` / `swap` / `proceed` / `apply` / `containsLeft` / `containsRight` surface; `left()` / `right()` / `both()` free constructors.
- feat(iter): add `Iter\merge_join_by` and `Iter\merge_join_by_key` - full-outer-join stream producers that yield `EitherOrBoth` events as a rewindable `Iter\Iterator`. `merge_join_by` is a lazy two-cursor merge over sorted inputs (O(1) memory on first traversal, `Psl\Comparison\Order`-returning comparator, matching Rust's `itertools::merge_join_by`); `merge_join_by_key` is a hash-based variant for keyed inputs that do not need to be pre-sorted (O(|right|) memory).
- feat(io): add `IO\IterableReadHandle` - a streaming `ReadHandleInterface` that lazily consumes an `iterable<string>` without buffering the entire content in memory
- feat(io): add `IO\ConcatReadHandle` - reads from two handles in sequence, switching to the second when the first reaches EOF
- feat(io): add `IO\JoinedReadWriteHandle` - joins a `ReadHandleInterface` and `WriteHandleInterface` into a single read-write handle, delegating all operations to the respective underlying handle
- feat(io): add `IO\TeeWriteHandle` - writes to two handles simultaneously with backpressure buffering when the second handle is slower
- feat(io): add `IO\SinkWriteHandle` - a `/dev/null`-like write handle that discards all written data
- feat(io): add `IO\SinkReadHandle` - a read handle that is always at EOF, unlike `MemoryHandle('')` which only reports EOF after the first read
- feat(io): add `IO\SinkReadWriteHandle` - a sink that discards writes and always reports EOF on reads
- feat(io): add `IO\TruncatedReadHandle` - reads up to N bytes from an underlying handle, silently reporting EOF when the limit is reached
- feat(io): add `IO\BoundedReadHandle` - reads up to N bytes from an underlying handle, throwing `RuntimeException` if the underlying handle has more data than the limit allows
- feat(io): add `IO\FixedLengthReadHandle` - reads exactly N bytes from an underlying handle, throwing `RuntimeException` on premature EOF
- feat(io): add `IO\copy_chunked()` and `IO\copy_bidirectional_chunked()` - variants of `IO\copy()` and `IO\copy_bidirectional()` that accept a custom chunk size
- feat(http-client): add `SendConfiguration::$connectionTimeout` - per-request maximum duration for establishing a connection (TCP + TLS handshake), using a linked cancellation token
- feat(type): support `'true'`/`'false'` string literals in `Type\bool()` coercion - [#735](https://github.com/php-standard-library/php-standard-library/pull/735) by @verweto
- feat(mime): introduce `MIME` component - comprehensive MIME toolkit implementing RFC 2045-2049 and related standards
  - Media type parsing, validation, and content negotiation (`MediaType`, `MediaRange`, `MediaPreferences`) per RFC 2045, RFC 6838, RFC 9110
  - MIME part construction with automatic transfer encoding (`Part\Text`, `Part\Data`) per RFC 2045
  - Streaming multipart body construction and parsing (`MultiPart\Composite`, `MultiPart\Alternative`, `MultiPart\Related`, `MultiPart\Form`, `MultiPart\Parser`) per RFC 2046, RFC 2387, RFC 7578
  - Immutable header collection with RFC 5322 line folding (`Headers`)
  - Content-Disposition parsing with safe filename extraction (`ContentDisposition`) per RFC 2183
  - Content-ID parsing, generation, and `cid:` URI support (`ContentId`) per RFC 2392
  - RFC 2231 parameter encoding/decoding with continuations and charset conversion (`Parameters`)
  - Content sniffing from bytes and seekable handles (`Sniff\from_string`, `Sniff\from_handle`)
  - S/MIME signing, verification, encryption, and decryption (`SMIME\Signer`, `SMIME\Verifier`, `SMIME\Encryptor`, `SMIME\Decryptor`) per RFC 5652, RFC 8551
  - DKIM message signing with RSA-SHA256 and Ed25519-SHA256 (`DKIM\Signer`) per RFC 6376, RFC 8301, RFC 8463
- feat(message): introduce `Message` component - RFC 5322 internet message construction, parsing, and serialization
  - Typed header fields with fluent `with*()` methods (`Message`) per RFC 5322
  - Address methods accept `string|Mailbox|AddressList` for convenience
  - Message body as `PartInterface` from the MIME component per RFC 2045
  - Streaming `serialize()` and `parse()` accepting string or `ReadHandleInterface`
  - Reply, reply-all, and forward with automatic threading headers (In-Reply-To, References) per RFC 5322
  - SMTP envelope derivation (`Envelope`) per RFC 5321
  - RFC 5322 address parsing: `Mailbox`, `Group`, `AddressList` with RFC 2047 encoded-word support
- feat(smtp): introduce `SMTP` component - RFC 5321 SMTP client with connection pooling, TLS, and authentication
  - Low-level `Connection` implementing `Network\StreamInterface` for protocol-level SMTP operations
  - High-level `Transport` managing the full SMTP lifecycle: connect, EHLO/HELO, STARTTLS, AUTH, send, RSET
  - Connection pooling with automatic reuse across multiple sends via `TCP\SocketPool`
  - Implicit TLS (port 465) and STARTTLS upgrade (RFC 3207) with automatic detection
  - EHLO with HELO fallback per RFC 5321
  - SMTP pipelining (RFC 2920) for reduced round-trips
  - BDAT chunking (RFC 3030) to avoid dot-stuffing overhead on large messages
  - Enhanced status codes (RFC 3463) with structured `EnhancedStatusCode` parsing
  - DSN delivery status notifications (RFC 3461) via `SendConfiguration`
  - REQUIRETLS (RFC 8689) for end-to-end TLS enforcement
  - MT-PRIORITY (RFC 6710) message priority with STANAG 4406 levels
  - DELIVERBY (RFC 2852) delivery deadline specification
  - FUTURERELEASE (RFC 4865) deferred delivery via `Duration` or `DateTimeInterface`
  - BINARYMIME (RFC 3030), 8BITMIME (RFC 6152), SMTPUTF8 (RFC 6531) capability negotiation
  - Punycode IDN encoding for internationalized domain names in addresses
  - Partial recipient success with `DeliveryReport` for per-recipient rejection tracking
  - CRLF and null byte injection protection via `PossibleAttackException`
  - Five authentication mechanisms: PLAIN (RFC 4616), LOGIN, XOAUTH2, CRAM-MD5 (RFC 2195), SCRAM-SHA-256 (RFC 7677)
  - Immutable `TransportConfiguration` and `SendConfiguration` with fluent `with*()` builders
  - Configurable pipelining, chunking, chunk size, and partial success behavior
- feat(dns): introduce `DNS` component - async DNS resolution with full protocol support
  - `SystemResolver` mirrors OS DNS behavior, usable as a default parameter value
  - UDP and pooled TCP resolvers with automatic TCP fallback on truncation
  - DNS-over-TLS (DoT) via TLS client configuration on `TCPResolver`
  - `RacingResolver` races multiple nameservers concurrently for fastest response
  - `SplitHorizonResolver` routes queries by domain name for split-horizon DNS
  - `SearchDomainResolver` expands short names using search domain lists
  - `HostsFileResolver` checks the OS hosts file before network queries
  - `CachedResolver` decorator with TTL-aware caching via `Cache\StoreInterface`
  - `StaticResolver` for hardcoded records in tests and development
  - Cross-platform system configuration loading (Linux, macOS, Windows) via async process execution
  - EDNS0 support: DNS cookies, client subnet, padding, NSID, TCP keepalive, key tag, extended DNS error
  - 20+ record types: A, AAAA, NS, CNAME, MX, TXT, SRV, SOA, PTR, CAA, SSHFP, TLSA, SVCB, HTTPS, LOC, NAPTR, DS, DNSKEY, RRSIG, NSEC, NSEC3
  - DNS-over-HTTPS (DoH) via `HTTPSResolver` using the HTTP client (RFC 8484)
  - DNS name validation with null byte and label length enforcement
  - `ResponseCode` helper methods: `isSuccess()`, `isError()`, `isServerError()`, `isNameError()`
- feat(dnssec): introduce `DNSSEC` component - full DNSSEC validation chain
  - `SecureResolver` validates RRSIG signatures on every response
  - `TrustChainResolver` walks DS/DNSKEY chain from root to target zone
  - `CachedTrustChainResolver` caches trust chain results for performance
  - `StaticTrustChainResolver` for offline/air-gapped environments
  - NSEC and NSEC3 authenticated denial of existence proof validation
  - 7 signature algorithms: RSA/SHA-1, RSA/SHA-256, RSA/SHA-512, ECDSA P-256, ECDSA P-384, Ed25519, Ed448
  - 4 specific validation exceptions: `SignatureFailedException`, `BrokenTrustChainException`, `InvalidProofException`, `UnsignedResponseException`
- feat(http-message): introduce `HTTP Message` component - version-agnostic HTTP message abstractions
  - `Request` and `Response` immutable value objects with streaming body (`ReadHandleInterface`)
  - `FieldMap` ordered, case-insensitive header field collection with lazy index
  - `ProtocolVersion` enum covering HTTP/1.0, HTTP/1.1, HTTP/2, and HTTP/3
  - Trailers modelled as `Async\Awaitable<FieldMap>` for HTTP/2 and chunked HTTP/1.1
  - HTTP status code and method constants per RFC 9110
  - `reason_phrase()` function for HTTP/1.x status line serialization
  - Fluent `with*()` mutation methods on both Request and Response
  - `Transaction` groups the final response with informational (1xx) responses and server push exchanges
  - `Exchange` represents a pushed request/response pair for HTTP/2 server push
- feat(http-client): introduce `HTTP Client` component - async HTTP/1.1 and HTTP/2 client with connection pooling
  - `Client` with automatic protocol negotiation via ALPN (HTTP/2 preferred, HTTP/1.1 fallback)
  - `PooledConnector` with HTTP/1.x idle connection reuse and HTTP/2 session sharing across concurrent requests
  - HTTP/2 multiplexing with event-driven stream dispatch via `H2Multiplexer` and per-stream `H2Stream` state
  - Transparent reconnection on connection failure (GOAWAY, TCP reset) via pool-backed reconnect closures
  - `RedirectClient` decorator following 301/302/303/307/308 redirects with method rewriting per RFC 9110, cross-origin credential stripping, and auto-referrer
  - `RetryClient` decorator with configurable exponential backoff and jitter for transport-level failures
  - `SendConfiguration` for per-request overrides (body size limits, TLS, protocol versions, tunnel) merged with `ClientConfiguration` defaults
  - `DeniedDestinationsMiddleware` for SSRF protection against private IP ranges (RFC 1918, RFC 4193, loopback, link-local)
  - Connection-level middleware via `HandlerInterface` / `MiddlewareInterface` chain with access to peer address and TLS state
  - SOCKS5 proxy support via `ClientConfiguration::$proxy` using `Psl\Socks\Connector`
  - HTTP CONNECT tunnel support via `ClientConfiguration::$tunnel` with TLS and proxy authentication
  - `noTunneling` host bypass rules (exact match, domain suffix, wildcard)
  - H2 flow control with BDP auto-tuning for dynamic receive window sizing
  - Lazy, pull-based response body reading via `ResponseBodyHandle` implementing `ReadHandleInterface`
  - 104 integration tests against httpbun covering methods, redirects, auth, caching, cookies, concurrency, and streaming
- feat(h2): introduce unified `Configuration` replacing deprecated `ClientConfiguration` and `ServerConfiguration`
  - Both `ClientConnection` and `ServerConnection` now accept `Configuration` in addition to their legacy config types
  - `ClientConnection` now supports BDP auto-tuning when using `Configuration` with `maxReceiveWindowSize` set
- feat(tcp): add `bindTo` option to `ConnectConfiguration` for binding to a specific local address before connecting
- feat(tcp): add `bindTo` option to `ListenConfiguration` for binding to a specific local address before listening
- feat(tcp): add `withBindTo()` fluent builder method to both `ConnectConfiguration` and `ListenConfiguration`
- feat(tcp): `connect()` now respects `ConnectConfiguration::$bindTo` by setting the `socket.bindto` stream context option
- feat(type): Allow null argument to `Type\class_string` to assert or coerce bare `class-string`

### type system

- chore(types): annotate read-only template parameters with `@template-covariant` across the library, allowing values to flow into wider declarations:
    - `Async\Awaitable<T>`, `Promise\PromiseInterface<T>`
    - `Result\ResultInterface<T>`, `Result\Success<T>`, `Result\Failure<T, Te>`
    - `Option\Option<T>`
    - `Either\Either<TLeft, TRight>`, `Either\Left<TLeft>`, `Either\Right<TRight>`
    - `Tree\NodeInterface<T>`, `Tree\LeafNode<T>`, `Tree\TreeNode<T>`
- chore(collection): annotate immutable collection template parameters with `@template-covariant` (`CollectionInterface`, `AccessibleCollectionInterface`, `IndexAccessInterface`, `MapInterface`, `Map`, `SetInterface`, `Set`, `VectorInterface`, `Vector`); mutable collections remain invariant.
- chore(async): annotate `Sequence`, `KeyedSequence`, `Semaphore`, `KeyedSemaphore` template parameters with the correct variance; keys/inputs are `@template-contravariant` (write-position only) and outputs are `@template-covariant` (read-position only).
- chore(collection): relax `Vector::getIterator()` and `MutableVector::getIterator()` return type from `Iterator<int<0, max>, T>` to `Iterator<int, T>`.

### fixes

- fix(io): `IO\copy()` now flushes the writer after copying if it implements `BufferedWriteHandleInterface`, ensuring no data remains in an internal buffer
- fix(async): `State::subscribe()` and `State::invokeCallbacks()` no longer capture `$this` in queued closures, preventing delayed garbage collection of `Deferred`/`Awaitable` chains
- fix(uri): bare IPv6 addresses (e.g., `http://::1/path`) are now correctly parsed as `IPHost` instead of being misparsed as a registered name with a numeric port
- fix(h2): separate `maxConcurrent` (peer's limit on our streams) from `peerMaxConcurrent` (our limit on peer's streams) in `StreamTable`, preventing the client's own SETTINGS from limiting its outgoing streams
- fix(h2): `BDPEstimator` now produces an initial connection-level WINDOW_UPDATE during `initialize()` to bring the receive window from the RFC default (65535) up to `initialWindowSize`, preventing flow-control stalls when many concurrent streams receive data simultaneously
- fix(h2): `notifyWindowWaiters()` now copies the waiter list before iterating and properly removes satisfied waiters, preventing iteration corruption and memory leaks
- fix(io): `ResourceHandle` readable/writable callbacks now null out the suspension reference before calling `resume()`, preventing "Must call suspend() before calling throw()" errors during handle destruction
- fix(io): `ResourceHandle::doRead()` and `doWrite()` cancellation subscriptions now null out the suspension reference before throwing, preventing double-wake when cancellation and close race during PHP shutdown
- fix(encoding): RFC 2047 encoded-word encoder no longer embeds CRLF line folding in the encoded output; line folding is now the responsibility of the header serializer, fixing header/body separation issues in serialized messages

### deprecations

- deprecated(tcp): `Socket` class -- use `ConnectConfiguration::$bindTo` or `ListenConfiguration::$bindTo` instead. Will be removed in PSL 7.0.
- deprecated(h2): `ClientConfiguration` -- use `Configuration` instead. Will be removed in PSL 7.0.
- deprecated(h2): `ServerConfiguration` -- use `Configuration` instead. Will be removed in PSL 7.0.

### ci

- ci: add httpbun, microsocks, and tinyproxy services to unit-tests, code-coverage, mutation-tests, and package-tests workflows for HTTP client integration testing

## 6.1.1

### fixes

- fix(str): `Str\chr()` now throws `OutOfBoundsException` for invalid Unicode code points instead of silently returning an empty string
- fix(str): `Str\from_code_points()` now validates code points and throws `OutOfBoundsException` for out-of-range values, surrogates, and negative inputs instead of producing invalid UTF-8; implementation now delegates to `Str\chr()` for consistent behavior

### other

- chore(str): clarify `width()`, `truncate()`, and `width_slice()` PHPDoc to explicitly reference `mb_strwidth()`/`mb_strimwidth()` semantics
- chore: make all function calls explicit across the codebase, eliminating PHP namespace fallback resolution
- chore(h2): skip timer-sensitive rate limiter test on Windows

## 6.1.0

### features

- feat(io): introduce `Psl\IO\BufferedWriteHandleInterface`, extending `WriteHandleInterface` with `flush()` for handles that buffer data internally before writing to an underlying resource
- feat: introduce `Compression` component with streaming compression/decompression abstractions for IO handles. Provides `CompressorInterface`, `DecompressorInterface`, four handle decorators (`CompressingReadHandle`, `CompressingWriteHandle`, `DecompressingReadHandle`, `DecompressingWriteHandle`), and convenience functions `compress()` and `decompress()`
- feat: introduce `HPACK` component - RFC 7541 HPACK header compression for HTTP/2
- feat: introduce `H2` component - HTTP/2 binary framing protocol implementation
- feat: introduce `Cache` component - async-safe in-memory LRU cache with per-key atomicity via `KeyedSequence`, proactive TTL expiration via event loop

## 6.0.1

- fix(io): `Reader::readUntil()` and `Reader::readUntilBounded()` no longer treat empty reads from non-blocking streams as EOF, fixing `readLine()` returning the entire content instead of individual lines when used with non-blocking streams
- fix(docs): source links now correctly point to `packages/{name}/src/Psl/` instead of the non-existent top-level `src/Psl/` path
- internal: add `splitter audit` command to verify organization repository settings (wiki, issues, discussions, PRs, tag immutability).

## 6.0.0

### breaking changes

- **BC** - All `null|Duration $timeout` parameters across IO, Network, TCP, TLS, Unix, UDP, Socks, Process, and Shell components have been replaced with `CancellationTokenInterface $cancellation = new NullCancellationToken()`. This enables both timeout-based and signal-based cancellation of async operations.
- **BC** - Removed `Psl\IO\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - Removed `Psl\Network\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - Removed `Psl\Process\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - Removed `Psl\Shell\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - `Psl\IO\CloseHandleInterface` now requires an `isClosed(): bool` method.
- **BC** - `Network\SocketInterface::getLocalAddress()` and `Network\StreamInterface::getPeerAddress()` no longer throw exceptions. Addresses are resolved at construction time and cached, making these O(1) property lookups with no syscall.
- **BC** - `BufferedReadHandleInterface::readLine()` now always splits on `"\n"` instead of `PHP_EOL`. Trailing `"\r"` is stripped, so both `"\n"` and `"\r\n"` line endings are handled consistently across all platforms. Use `readUntil(PHP_EOL)` for system-dependent behavior.
- **BC** - `Psl\TLS\ServerConfig` renamed to `Psl\TLS\ServerConfiguration`.
- **BC** - `Psl\TLS\ClientConfig` renamed to `Psl\TLS\ClientConfiguration`.
- **BC** - All variables and parameters across the codebase now use `$camelCase` naming instead of `$snake_case`.
- **BC** - `TCP\listen()`, `TCP\connect()`, `TCP\Socket::listen()`, `TCP\Socket::connect()` now accept configuration objects (`TCP\ListenConfiguration`, `TCP\ConnectConfiguration`) instead of individual parameters for socket options.
- **BC** - `Unix\listen()` and `Unix\Socket::listen()` now accept `Unix\ListenConfiguration` instead of individual parameters.
- **BC** - `UDP\Socket::bind()` now accepts `UDP\BindConfiguration` instead of individual parameters.
- **BC** - `TCP\Socket` setter/getter methods (`setReuseAddress`, `setReusePort`, `setNoDelay`, etc.) have been removed. Use configuration objects instead.
- **BC** - `TCP\Connector` constructor now accepts `TCP\ConnectConfiguration` instead of `bool $noDelay`.
- **BC** - `Socks\Connector` constructor changed from `(string $proxyHost, int $proxyPort, ?string $username, ?string $password, ConnectorInterface $connector)` to `(ConnectorInterface $connector, Socks\Configuration $configuration)`.
- **BC** - Renamed `ingoing` to `ongoing` across `Semaphore`, `Sequence`, `KeyedSemaphore`, and `KeyedSequence` (`hasIngoingOperations()` -> `hasOngoingOperations()`, `getIngoingOperations()` -> `getOngoingOperations()`, etc.).

### features

- feat(async): introduce `Psl\Async\CancellationTokenInterface` for cancelling async operations
- feat(async): introduce `Psl\Async\NullCancellationToken` - no-op token used as default parameter value
- feat(async): introduce `Psl\Async\SignalCancellationToken` - manually triggered cancellation via `cancel(?Throwable $cause)`
- feat(async): introduce `Psl\Async\TimeoutCancellationToken` - auto-cancels after a `Duration`, replacing the old `Duration $timeout` pattern
- feat(async): introduce `Psl\Async\LinkedCancellationToken` - cancelled when either of two inner tokens is cancelled, useful for combining a request-scoped token with an operation-specific timeout
- feat(async): introduce `Psl\Async\Exception\CancelledException` - thrown when a cancellation token is triggered; the cause (e.g., `TimeoutException`) is attached as `$previous`. Use `$e->getToken()` to identify which token triggered the cancellation.
- feat(async): `Async\sleep()` now accepts an optional `CancellationTokenInterface` parameter, allowing early wake-up on cancellation
- feat(async): `Awaitable::await()` now accepts an optional `CancellationTokenInterface` parameter
- feat(async): `Sequence::waitFor()` and `Sequence::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(async): `Semaphore::waitFor()` and `Semaphore::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(async): `KeyedSequence::waitFor()` and `KeyedSequence::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(async): `KeyedSemaphore::waitFor()` and `KeyedSemaphore::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(channel): `SenderInterface::send()` and `ReceiverInterface::receive()` now accept an optional `CancellationTokenInterface` parameter
- feat(network): `ListenerInterface::accept()` now accepts an optional `CancellationTokenInterface` parameter
- feat(tcp): `TCP\ListenerInterface::accept()` now accepts an optional `CancellationTokenInterface` parameter
- feat(unix): `Unix\ListenerInterface::accept()` now accepts an optional `CancellationTokenInterface` parameter
- feat(tls): `TLS\Acceptor::accept()`, `TLS\LazyAcceptor::accept()`, `TLS\ClientHello::complete()`, and `TLS\Connector::connect()` now accept an optional `CancellationTokenInterface` parameter - cancellation propagates through the TLS handshake
- feat(tls): `TLS\TCPConnector::connect()` and `TLS\connect()` now pass the cancellation token through to the TLS handshake
- feat(async): introduce `Psl\Async\TaskGroup` for running closures concurrently and awaiting them all with `defer()` + `awaitAll()`
- feat(async): introduce `Psl\Async\WaitGroup`, a counter-based synchronization primitive with `add()`, `done()`, and `wait()`
- feat(encoding): introduce `Psl\Encoding\QuotedPrintable\encode()`, `decode()`, and `encode_line()` for RFC 2045 quoted-printable encoding with configurable line length and line ending
- feat(encoding): introduce `Psl\Encoding\EncodedWord\encode()` and `decode()` for RFC 2047 encoded-word encoding/decoding in MIME headers (B-encoding and Q-encoding with automatic selection)
- feat(tls): introduce `TLS\ListenerInterface` and `TLS\Listener`, wrapping any `Network\ListenerInterface` to perform TLS handshakes on accepted connections
- feat(encoding): add `Base64\Variant::Mime` for RFC 2045 MIME Base64 with 76-char line wrapping and CRLF, using constant-time encoding/decoding
- feat(encoding): introduce streaming IO handles for Base64 (`EncodingReadHandle`, `DecodingReadHandle`, `EncodingWriteHandle`, `DecodingWriteHandle`), QuotedPrintable (same 4), and Hex (same 4), bridging `Psl\IO` and `Psl\Encoding` for transparent encode/decode on read/write
- feat(io): introduce `Psl\IO\BufferedReadHandleInterface`, extending `ReadHandleInterface` with `readByte()`, `readLine()`, `readUntil()`, and `readUntilBounded()`
- feat(io): `Psl\IO\Reader` now implements `BufferedReadHandleInterface`
- feat(tcp): introduce `TCP\ListenConfiguration` and `TCP\ConnectConfiguration` with immutable `with*` builder methods
- feat(unix): introduce `Unix\ListenConfiguration` with immutable `with*` builder methods
- feat(udp): introduce `UDP\BindConfiguration` with immutable `with*` builder methods
- feat(socks): introduce `Socks\Configuration` with immutable `with*` builder methods for proxy host, port, and credentials
- feat(tcp): introduce `TCP\RestrictedListener`, wrapping a listener to restrict connections to a set of allowed `IP\Address` and `CIDR\Block` entries
- feat(network): introduce `Network\CompositeListener`, accepting connections from multiple listeners concurrently through a single `accept()` call
- feat: introduce `URI` component - RFC 3986 URI parsing, normalization, reference resolution, and RFC 6570 URI Template expansion (Levels 1–4), with RFC 5952 IPv6 canonical form and RFC 6874 zone identifiers
- feat: introduce `IRI` component - RFC 3987 Internationalized Resource Identifier parsing with Unicode support, RFC 3492 Punycode encoding/decoding, and RFC 5891/5892 IDNA 2008 domain name processing
- feat: introduce `URL` component - strict URL type with scheme and authority validation, default port stripping for known schemes, and URI/IRI conversion
- feat: introduce `Punycode` component - RFC 3492 Punycode encoding and decoding for internationalized domain names
- fix(tcp): `RetryConnector` backoff sleep now respects cancellation tokens, allowing retry loops to be cancelled during the delay
- fix(io, str): `IO\write()`, `IO\write_line()`, `IO\write_error()`, `IO\write_error_line()`, and `Str\format()` no longer pass the message through `sprintf`/`vsprintf` when no arguments are given, preventing format string errors when the message contains `%` characters

### migration guide

Replace `Duration` timeout parameters with `TimeoutCancellationToken`:

```php
// Before (5.x)
$data = $reader->read(timeout: Duration::seconds(5));

// After (6.0)
$data = $reader->read(cancellation: new Async\TimeoutCancellationToken(Duration::seconds(5)));
```

For manual cancellation (e.g., cancel all request IO when a client disconnects):

```php
$token = new Async\SignalCancellationToken();

// Pass to all request-scoped IO
$body = $reader->readAll(cancellation: $token);

// Cancel from elsewhere
$token->cancel();
```

## 5.5.0

### features

- feat(io): added `Reader::readUntilBounded(string $suffix, int $max_bytes, ?Duration $timeout)` method, which reads until a suffix is found, but throws `IO\Exception\OverflowException` if the content exceeds `$max_bytes` before the suffix is encountered - [#620](https://github.com/php-standard-library/php-standard-library/pull/620) - by @azjezz
- feat(io): added `IO\Exception\OverflowException` exception class - [#620](https://github.com/php-standard-library/php-standard-library/pull/620) - by @azjezz
- feat(type): add `Type\json_decoded()` type for transparent JSON string coercion - [#619](https://github.com/php-standard-library/php-standard-library/pull/619) by @veewee
- feat(type): add `Type\nullish()` type for optional-and-nullable shape fields - [#618](https://github.com/php-standard-library/php-standard-library/pull/618) by @veewee

## 5.4.0

### features

- feat(dict, vec): add filter_nonnull_by and map_nonnull - [#576](https://github.com/php-standard-library/php-standard-library/pull/576) by @Dima-369
* feat(tcp): add `backlog` parameter to `TCP\listen()` for configuring the pending connection queue size - [#617](https://github.com/php-standard-library/php-standard-library/pull/617) - by @azjezz
* feat(tcp): listener now drains the accept backlog in a loop for higher throughput - [#617](https://github.com/php-standard-library/php-standard-library/pull/617) - by @azjezz

### other

* chore: update dev dependencies, and re-format the codebase using latest mago version - [#616](https://github.com/php-standard-library/php-standard-library/pull/616) by @azjezz

## 5.3.0

### features

* feat(io): introduce `IO\spool()` for memory-backed handles that spill to disk

## 5.2.0

### features

* feat: introduce `IP` component with immutable, binary-backed `Address` value object and `Family` enum
* feat(cidr): `CIDR\Block::contains()` now accepts `string|IP\Address`

## 5.1.0

### features

* feat(tls): introduce `TLS\TCPConnector` for poolable TLS connections
* feat(tls): `TLS\StreamInterface` now extends `TCP\StreamInterface`, enabling TLS streams to be used with `TCP\SocketPoolInterface`

## 5.0.0

### breaking changes

* Dropped PHP 8.3 support; minimum is now PHP 8.4 - [#584](https://github.com/php-standard-library/php-standard-library/pull/584) by @azjezz
* Migrated to PHPUnit 13 - [#584](https://github.com/php-standard-library/php-standard-library/pull/584) by @azjezz
* Complete networking stack rewrite (`Network`, `TCP`, `Unix`) - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* `Psl\Shell` internals refactored; dead code removed - [#596](https://github.com/php-standard-library/php-standard-library/pull/596) by @azjezz
* `Psl\Env\temp_dir()` now always returns a canonicalized path - [#599](https://github.com/php-standard-library/php-standard-library/pull/599) by @azjezz

### features

* feat: introduce `Ansi` component - [#588](https://github.com/php-standard-library/php-standard-library/pull/588) by @azjezz
* feat: introduce `Terminal` component - [#589](https://github.com/php-standard-library/php-standard-library/pull/589) by @azjezz
* feat: introduce `Process` component - [#578](https://github.com/php-standard-library/php-standard-library/pull/578) by @azjezz
* feat: introduce `Binary` component - [#598](https://github.com/php-standard-library/php-standard-library/pull/598) by @azjezz
* feat: introduce `Interoperability` component - [#582](https://github.com/php-standard-library/php-standard-library/pull/582) by @azjezz
* feat: introduce `TLS` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat: introduce `UDP` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat: introduce `CIDR` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat: introduce `Socks` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat(network): connection pooling, retry logic, socket pairs - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat(datetime): add `Period`, `Interval`, `TemporalAmountInterface` - [#595](https://github.com/php-standard-library/php-standard-library/pull/595) by @azjezz
* feat(io): add `IO\copy()` and `IO\copy_bidirectional()` - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat(vec): add `Vec\flatten()` - [#583](https://github.com/php-standard-library/php-standard-library/pull/583) by @azjezz
* feat: introduce `Crypto` component with symmetric/asymmetric encryption, signing, AEAD, KDF, HKDF, key exchange, and stream ciphers - [#607](https://github.com/php-standard-library/php-standard-library/pull/607) by @azjezz

### fixes, and improvements

* fix(vec): strict comparison in `range()` for float precision - [#581](https://github.com/php-standard-library/php-standard-library/pull/581) by @azjezz
* fix(filesystem): canonicalize temporary directory for `create_temporary_file` - [#580](https://github.com/php-standard-library/php-standard-library/pull/580), [#597](https://github.com/php-standard-library/php-standard-library/pull/597) by @azjezz

### other

* docs: documentation website at https://php-standard-library.dev/ - [#592](https://github.com/php-standard-library/php-standard-library/pull/592), [#594](https://github.com/php-standard-library/php-standard-library/pull/594) by @azjezz
* perf: performed optimizations across multiple components, which benchmarks showing up to 100% improvements in certain cases/functions.

## 4.3.0

### features

* feat: introduce `Either` type - [#572](https://github.com/php-standard-library/php-standard-library/pull/572) by @simPod
* feat(type): add `uuid` type - [#568](https://github.com/php-standard-library/php-standard-library/pull/568) by @gsteel

### fixes, and improvements

* fix(shell): terminate the process on timeout - [#574](https://github.com/php-standard-library/php-standard-library/pull/574) by @azjezz
* fix(io): correct PHPDoc return type annotation - [#571](https://github.com/php-standard-library/php-standard-library/pull/571) by @mitelg
* refactor(phpunit): resolve test case naming deprecations - [#573](https://github.com/php-standard-library/php-standard-library/pull/573) by @simPod

## 4.2.1

### fixes, and improvements

* fix(tree): explicit type precedence - [#566](https://github.com/php-standard-library/php-standard-library/pull/566) by @azjezz
* fix(iter): do not narrow down `seek($offset)` type - [#552](https://github.com/php-standard-library/php-standard-library/pull/552) by @azjezz
* fix(filesystem): release handles before changing permissions when copying files - [#550](https://github.com/php-standard-library/php-standard-library/pull/550) by @dragosprotung
* revert(option): revert [#475](https://github.com/php-standard-library/php-standard-library/pull/475) - [#560](https://github.com/php-standard-library/php-standard-library/pull/560) by @devnix

## 4.2.0

### other

* chore: add support for PHP 8.5 - [#549](https://github.com/php-standard-library/php-standard-library/pull/549) by @veewee

## 4.1.0

### features

* feat: add `Graph` component with directed and undirected graph support - [#547](https://github.com/php-standard-library/php-standard-library/pull/547) by @azjezz
* feat: add `Tree` component for hierarchical data structures - [#546](https://github.com/php-standard-library/php-standard-library/pull/546) by @azjezz
* feat(type): add reflection-based type functions for class members - [#543](https://github.com/php-standard-library/php-standard-library/pull/543) by @azjezz

### other

* chore: migrate from `make` to `just` - [#544](https://github.com/php-standard-library/php-standard-library/pull/544) by @azjezz

## 4.0.1

### fixes, and improvements

* refactor: remove redundant `@var` tags from constants - [#533](https://github.com/php-standard-library/php-standard-library/pull/533) by @azjezz

## 4.0.0

### breaking changes

* `Psl\Result\wrap()` no longer unwraps nested results - [#531](https://github.com/php-standard-library/php-standard-library/pull/531) by @azjezz
* `Psl\Collection\Map`, `Psl\Collection\MutableMap`, `Psl\Collection\Set`, and `Psl\Collection\MutableSet` now have a more natural JSON serialization - [#512](https://github.com/php-standard-library/php-standard-library/pull/512) by @josh-rai
* A large number of intersection interfaces in the `Psl\IO` and `Psl\File` namespaces have been removed to simplify the component's hierarchy - [#518](https://github.com/php-standard-library/php-standard-library/pull/518) by @azjezz
* `Psl\sequence()` function has been removed - [#519](https://github.com/php-standard-library/php-standard-library/pull/519) by @azjezz

### features

* feat(type): add `container` type - [#513](https://github.com/php-standard-library/php-standard-library/pull/513) by @azjezz
* feat(type): add `int_range` type - [#510](https://github.com/php-standard-library/php-standard-library/pull/510) by @george-steel
* feat(type): add `always_assert` type - [#522](https://github.com/php-standard-library/php-standard-library/pull/522) by @azjezz
* feat(iter): add `search_with_keys_opt` and `search_with_keys` functions - [#490](https://github.com/php-standard-library/php-standard-library/pull/490) by @simon-podlipsky

### fixes, and improvements

* refactor: improve type inference for non-empty lists - [#529](https://github.com/php-standard-library/php-standard-library/pull/529) by @azjezz
* refactor: improve type inference for `Iter` and `Regex` - [#528](https://github.com/php-standard-library/php-standard-library/pull/528) by @azjezz

### other

* chore: migrate from `psalm` to `mago` - [#527](https://github.com/php-standard-library/php-standard-library/pull/527) by @azjezz
* chore: replace psalm-specific tags by generic tags - [#531](https://github.com/php-standard-library/php-standard-library/pull/531) by @azjezz

## 2.7.0

### features

* feat(encoding): introduce `Base64\Variant` enum to support encoding/decoding different variants - [#408](https://github.com/php-standard-library/php-standard-library/pull/408) by @Gashmob

### fixes, and improvements

* fix(option): return `Option<never>` for `Option::none()` - [#415](https://github.com/php-standard-library/php-standard-library/pull/415) by @devnix
* fix(str): add invariant to avoid unexpected errors when parsing an invalid UTF8 string - [#410](https://github.com/php-standard-library/php-standard-library/pull/410) by @devnix

## 2.6.0

### features

* feat(type): introduce `Type\converted` function - [#405](https://github.com/php-standard-library/php-standard-library/pull/405) by @veewee
* feat(type): introduce `Type\numeric_string` function - [#406](https://github.com/php-standard-library/php-standard-library/pull/406) by @veewee

## 2.5.0

### features

* feat(result): introduce `Result\try_catch` function - [#403](https://github.com/php-standard-library/php-standard-library/pull/403) by @azjezz

### fixes, and improvements

* fix(file): improve consistency when creating files for write-mode - [#401](https://github.com/php-standard-library/php-standard-library/pull/401) by @veewee

## 2.4.1

### fixes, and improvements

* fix(type): un-deprecate `Psl\Type\positive_int` function - [#400](https://github.com/php-standard-library/php-standard-library/pull/400) by @dragosprotung

## 2.4.0

### features

* feat(range): introduced `Psl\Range` component - [#378](https://github.com/php-standard-library/php-standard-library/pull/378) by @azjezz
* feat(str): introduced `Psl\Str\range`, `Psl\Str\Byte\range`, and `Psl\Str\Grapheme\range` functions - [#385](https://github.com/php-standard-library/php-standard-library/pull/385) by @azjezz
* feat(type): introduced `Psl\Type\uint` function - [#393](https://github.com/php-standard-library/php-standard-library/pull/393) by @azjezz
* feat(type): introduced `Psl\Type\i8`, `Psl\Type\i16`, `Psl\Type\i32`, `Psl\Type\i64` functions - [#392](https://github.com/php-standard-library/php-standard-library/pull/392) by @azjezz
* feat(type): introduced `Psl\Type\u8`, `Psl\Type\u16`, `Psl\Type\u32` functions - [#395](https://github.com/php-standard-library/php-standard-library/pull/395) by @KennedyTedesco
* feat(type): introduced `Psl\Type\f32`, and `Psl\Type\f64` functions - [#396](https://github.com/php-standard-library/php-standard-library/pull/396) by @KennedyTedesco
* feat(type): introduced `Psl\Type\nonnull` function - [#392](https://github.com/php-standard-library/php-standard-library/pull/392) by @azjezz
* feat(option): improve options type declarations and add `andThen` method - [#398](https://github.com/php-standard-library/php-standard-library/pull/398) by @veewee

### fixes, and improvements

* fix(vec/dict): Return might be non-empty-list/non-empty-array for map functions - [#384](https://github.com/php-standard-library/php-standard-library/pull/384) by @dragosprotung

### other

* chore(async): add async component documentation - [#386](https://github.com/php-standard-library/php-standard-library/pull/386) by @azjezz

### deprecations

* deprecated `Psl\Type\positive_int` function, use `Psl\Type\uint` instead - by @azjezz

## 2.3.1

### fixes, and improvements

* fix(vec): `Vec\reproduce` and `Vec\range` return type is always non-empty-list - [#383](https://github.com/php-standard-library/php-standard-library/pull/383) by @dragosprotung

### other

* chore: update license copyright year - [#371](https://github.com/php-standard-library/php-standard-library/pull/371) by @azjezz

## 2.3.0

### other

* chore: support psalm v5 - [#369](https://github.com/php-standard-library/php-standard-library/pull/369) by @veewee


## 2.2.0

### features

* feat(option): introduce option component - [#356](https://github.com/php-standard-library/php-standard-library/pull/356) by @azjezz

## 2.1.0

### features

* introduced a new `Psl\Type\unit_enum` function - [@19d1230](https://github.com/php-standard-library/php-standard-library/commit/19d123074546cc3ebfca18ad666f100e7fad0658) by @azjezz
* introduced a new `Psl\Type\backed_enum` function - [@19d1230](https://github.com/php-standard-library/php-standard-library/commit/19d123074546cc3ebfca18ad666f100e7fad0658) by @azjezz
* introduced a new `Psl\Type\mixed_vec` function - [#362](https://github.com/php-standard-library/php-standard-library/pull/362) by @BackEndTea
* introduced a new `Psl\Type\mixed_dict` function - [#362](https://github.com/php-standard-library/php-standard-library/pull/362) by @BackEndTea

### fixes, and improvements

* improved `Psl\Type\vec` performance - [#364](https://github.com/php-standard-library/php-standard-library/pull/364) by @BackEndTea
* improved `Psl\Type\float`, and `Psl\Type\num` - [#367](https://github.com/php-standard-library/php-standard-library/pull/367) by @bcremer

### other

* updated `revolt-php/event-loop` to `1.0.0` - [@c7bf866](https://github.com/php-standard-library/php-standard-library/commit/c7bf866a362b9528934a758981da718408ec15d4) by @azjezz
* introduced scope-able loader - [#361](https://github.com/php-standard-library/php-standard-library/pull/361) by @veewee
* fixed wrong function names in examples - [#354](https://github.com/php-standard-library/php-standard-library/pull/354) by @jrmajor
* added reference to PHPStan integration in README.md - [#353](https://github.com/php-standard-library/php-standard-library/pull/353) by @ondrejmirtes

## 2.0.0

* **BC** - removed `Psl\Arr` component.
* **BC** - removed `Psl\Type\is_array`, `Psl\Type\is_arraykey`, `Psl\Type\is_bool`, `Psl\Type\is_callable`, `Psl\Type\is_float`, `Psl\Type\is_instanceof`, `Psl\Type\is_int`, `Psl\Type\is_iterable`, `Psl\Type\is_null`, `Psl\Type\is_numeric`, `Psl\Type\is_object`, `Psl\Type\is_resource`, `Psl\Type\is_scalar`, and `Psl\Type\is_string` functions ( use `TypeInterface::matches($value)` instead ).
* **BC** - removed `Psl\Iter\chain`, `Psl\Iter\chunk`, `Psl\Iter\chunk_with_keys`, `Psl\Iter\diff_by_key`, `Psl\Iter\drop`, `Psl\Iter\drop_while`, `Psl\Iter\enumerate`, `Psl\Iter\filter`, `Psl\Iter\filter_keys`, `Psl\Iter\filter_nulls`, `Psl\Iter\filter_with_key`, `Psl\Iter\flat_map`, `Psl\Iter\flatten`, `Psl\Iter\flip`, `Psl\Iter\from_entries`, `Psl\Iter\from_keys`, `Psl\Iter\keys`, `Psl\Iter\map`, `Psl\Iter\map_keys`, `Psl\Iter\map_with_key`, `Psl\Iter\merge`, `Psl\Iter\product`, `Psl\Iter\pull`, `Psl\Iter\pull_with_key`, `Psl\Iter\range`, `Psl\Iter\reductions`, `Psl\Iter\reindex`, `Psl\Iter\repeat`, `Psl\Iter\reproduce`, `Psl\Iter\reverse`, `Psl\Iter\slice`, `Psl\Iter\take`, `Psl\Iter\take_while`, `Psl\Iter\to_array`, `Psl\Iter\to_array_with_keys`, `Psl\Iter\values`, and `Psl\Iter\zip` functions.
* **BC** - signature of `Psl\Iter\reduce_keys` function changed from `reduce_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(?Ts, Tk): Ts) $function, Ts|null $initial = null): Ts|null` to `reduce_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(Ts, Tk): Ts) $function, Ts $initial): Ts`.
* **BC** - signature of `Psl\Iter\reduce_with_keys` function changed from `reduce_with_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(?Ts, Tk, Tv): Ts) $function, Ts|null $initial = null): Ts|null` to `reduce_with_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(Ts, Tk, Tv): Ts) $function, Ts $initial): Ts`.
* **BC** - removed bundled psalm plugin `Psl\Integration\Psalm\Plugin`, use `php-standard-library/psalm-plugin` package instead.
* dropped support for PHP 8.0
* **BC** - signature of `Psl\Type\object` function changed from `object<T of object>(classname<T> $classname): TypeInterface<T>` to `object(): TypeInterface<object>` ( to preserve the old behavior, use `Psl\Type\instance_of` )
* introduced `Psl\Type\instance_of` function, with the signature of `instance_of<T of object>(classname<T> $classname): TypeInterface<T>`.
* introduced a new `Psl\Async` component.
* refactored `Psl\IO` handles API.
* introduced a new `Psl\File` component.
* refactor `Psl\Shell\execute` to use `Psl\IO` component.
* introduced a `Psl\IO\pipe(): (Psl\IO\CloseReadHandleInterface, Psl\IO\CloseWriteHandleInterface)` function to create a pair of handles, where writes to the WriteHandle can be read from the ReadHandle.
* **BC** - `$encoding` argument for `Psl\Str` functions now accepts `Psl\Str\Encoding` instead of `?string`.
* introduced a new `Psl\Runtime` component.
* introduced a new `Psl\Network` component.
* introduced a new `Psl\TCP` component.
* introduced a new `Psl\Unix` component.
* introduced a new `Psl\Channel` component.
* introduced a new `IO\write()` function.
* introduced a new `IO\write_line()` function.
* introduced a new `IO\write_error()` function.
* introduced a new `IO\write_error_line()` functions.
* introduced a new `Psl\Html\Encoding` enum.
* **BC** - `$encoding` argument for `Psl\Html` functions now accepts `Psl\Html\Encoding` instead of `?string`.
* **BC** - `Psl\Shell\escape_command` function has been removed, no replacement is available.
* introduced a new `Psl\Math\acos` function.
* introduced a new `Psl\Math\asin` function.
* introduced a new `Psl\Math\atan` function.
* introduced a new `Psl\Math\atan2` function.
* **BC** - The type of the $numbers argument of `Psl\Math\mean` has changed to `list<int|float>` instead of `iterable<int|float>`.
* **BC** - The type of the $numbers argument of `Psl\Math\median` has changed to `list<int|float>` instead of `iterable<int|float>`.
* introduced a new `Psl\Promise` component.
* **BC** - `Psl\Result\ResultInterface` now implements `Psl\Promise\PromiseInterface`
* **BC** - `Psl\Type\resource('curl')->toString()` now uses PHP built-in resource kind notation ( i.e: `resource (curl)` ) instead of generic notation ( i.e: `resource<curl>` )
* **BC** - `Psl\Str`, `Psl\Str\Byte`, and `Psl\Str\Grapheme` functions now throw `Psl\Str\Exception\OutOfBoundsException` instead of `Psl\Exception\InvaraintViolationsException` when `$offset` is out-of-bounds.
* **BC** - `Psl\Collection\IndexAccessInterface::at()` now throw `Psl\Collection\Exception\OutOfBoundsException` instead of `Psl\Exception\InvariantViolationException` if `$k` is out-of-bounds.
* **BC** - `Psl\Collection\AccessibleCollectionInterface::slice` signature has changed from `slice(int $start, int $length): static` to `slice(int $start, ?int $length = null): static`
* **BC** - All psl functions previously accepting `callable`, now accept only `Closure`.
* **BC** - `Psl\DataStructure\QueueInterface::dequeue`, and `Psl\DataStructure\StackInterface::pop` now throw `Psl\DataStructure\Exception\UnderflowException` instead of `Psl\Exception\InvariantViolationException` when the data structure is empty.
* **BC** - `Psl\Filesystem\write_file($file, $content)` function has been removed, use `Psl\File\write($file, $content);` instead. 
  > To preserve the same behavior as the old function, use `Psl\File\write($file, $content, Filesystem\is_file($file) ? File\WriteMode::TRUNCATE : File\WriteMode::OPEN_OR_CREATE)`.
* **BC** - `Psl\Filesystem\read_file($file, $offset, $length)` function has been removed, use `Psl\File\read($file, $offset, $length)` instead.
* **BC** - `Psl\Filesystem\append_file($file, $contents)` function has been removed, use `Psl\File\write($file, $contents, File\WriteMode::APPEND)` instead.
* **BC** - `Psl\Filesystem` functions no longer throw `Psl\Exception\InvariantViolationException`.

  New exceptions:
  - `Psl\Filesystem\Exception\NotReadableException` thrown when attempting to read from a non-readable node
  - `Psl\Filesystem\Exception\NotFileException` thrown when attempting a file operation on a non-file node.
  - `Psl\Filesystem\Exception\NotDirectoryException` thrown when attempting a directory operation on a non-directory node.
  - `Psl\Filesystem\Exception\NotSymbolicLinkException` thrown when attempting a symbolic link operation on a non-symbolic link node.
  - `Psl\Filesystem\Exception\NotFoundException` thrown when attempting an operation on a non-existing node.
* introduced `Psl\Hash\Algorithm` enum.
* introduced `Psl\Hash\Hmac\Algorithm` enum.
* **BC** - `Psl\Hash\hash`, and `Psl\Hash\Context::forAlgorithm` now take `Psl\Hash\Algorithm` as an algorithm, rather than a string.
* **BC** - `Psl\Hash\Hmac\hash`, and `Psl\Hash\Context::hmac` now take `Psl\Hash\Hmac\Algorithm` as an algorithm, rather than a string.
* **BC** - A new method `chunk(positive-int $size): CollectionInterface` has been added to `Psl\Collection\CollectionInterface`.
* introduced a new `Psl\OS` component.
* introduced `Psl\Password\Algorithm` enum
* **BC** - all constants of `Psl\Password` component has been removed.
* **BC** - function `Psl\Password\algorithms()` have been removed.
* **BC** - `Psl\Result\ResultInterface::getException()` method has been renamed to `Psl\Result\ResultInterface::getThrowable()`
* **BC** - `Psl\Result\wrap` function now catches all `Throwable`s instead of only `Exception`s
* introduced a new `Psl\Result\reflect` function
* **BC** - `Psl\Shell\escape_argument` function has been removed, `Shell\execute` arguments are now always escaped.
* **BC** - `$escape_arguments` argument of `Shell\execute` function has been removed.
* introduced a new `Psl\Shell\ErrorOutputBehavior` enum
* added a new `$error_output_behavior` argument to `Shell\execute` function, which can be used to return the command error output content, as well as the standard output content.
* introduced a new `Psl\Shell\unpack` function to unpack packed result of `Shell\execute` ( see `Psl\Shell\ErrorOutputBehavior::Packed` ).
* introduced a new `Psl\Shell\stream_unpack` function to unpack packed result of `Shell\execute` chunk by chunk, maintaing order ( see `Psl\Shell\ErrorOutputBehavior::Packed` ).
