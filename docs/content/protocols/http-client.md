# HTTP Client

The `HTTP Client` component provides an async HTTP client with connection pooling, HTTP/2 multiplexing, automatic protocol negotiation via ALPN, middleware support, and composable decorators for redirects and retries. All I/O is non-blocking and built on the PSL async runtime.

## Basic Usage

Create a client, build a request, and send it. The returned transaction contains the response with status, headers, and a streaming body.

@example('protocols/http-client-basic.php')

## Sending Data

Send a POST request with a JSON body and custom headers using `IO\MemoryHandle` for the request body.

@example('protocols/http-client-sending-data.php')

## Concurrent Requests

Use `Async\concurrently()` to send multiple requests in parallel over the same client. HTTP/2 connections are automatically multiplexed.

@example('protocols/http-client-concurrent.php')

## Redirects

`RedirectClient` wraps any client and automatically follows 3xx redirects with credential stripping on cross-origin hops and method rewriting per RFC 9110.

Redirects that preserve the request body, including 307 and 308, require a seekable body. The client restores its original position before each resend and throws `Exception\RequestException` if it cannot rewind the body.

@example('protocols/http-client-redirects.php')

## Retries

`RetryClient` wraps any client and retries idempotent requests on transport-level failures with exponential backoff.

@example('protocols/http-client-retries.php')

## Configuration

`ClientConfiguration` controls TLS settings, protocol version preferences, response size limits, base URL, and proxy configuration.

@example('protocols/http-client-configuration.php')

## Per-Request Overrides

`SendConfiguration` overrides specific client settings for a single request without affecting the client default.

@example('protocols/http-client-send-configuration.php')

## SSRF Protection

`DeniedDestinationsMiddleware` inspects the resolved peer IP after connection establishment and blocks requests to private, loopback, and link-local addresses.

@example('protocols/http-client-ssrf.php')

## Proxy Modes

The client supports three proxy modes, configured via `ClientConfiguration` or overridden per-request via `SendConfiguration`:

- **No proxy** (default): The client connects directly to the target server.
- **HTTP proxy** (`$proxyConfiguration`): Configured via a `ProxyConfiguration` object that holds the proxy URL, optional `Proxy-Authorization` header, TLS SNI hostname, and bypass list. For HTTPS targets, the client connects to the proxy and issues an HTTP/1.1 CONNECT request to create a tunnel. For plain HTTP targets, the client connects to the proxy directly and uses absolute-form request targets per RFC 7230 Section 5.3.2 (forward proxying). The `$skipProxyFor` list on the configuration specifies hosts that bypass the proxy entirely.
- **SOCKS5 proxy** (`$socksConfiguration`): All TCP connections are routed through the SOCKS5 proxy server. TLS, ALPN negotiation, and HTTP framing happen on top of the tunneled connection unchanged.

Both proxy types can be combined: when both `$socksConfiguration` and `$proxyConfiguration` are set, the SOCKS5 proxy tunnels the TCP connection to the HTTP proxy, which then handles HTTP forward proxying or CONNECT tunneling.

## SOCKS Proxy

Route all TCP connections through a SOCKS5 proxy using `$socksConfiguration`. TLS and ALPN negotiation happen on top of the tunneled connection.

@example('protocols/http-client-proxy.php')

## HTTP Proxy

Use `$proxyConfiguration` to route requests through an HTTP proxy. Configure the proxy URL, authentication, and bypass rules via `ProxyConfiguration`. HTTPS targets use CONNECT tunneling while plain HTTP targets use forward proxying with absolute-form request targets.

@example('protocols/http-client-tunnel.php')

## HTTP/2 over Plaintext (h2c)

For servers that support HTTP/2 without TLS, set `protocolVersions` to only `ProtocolVersion::V20`. The client uses prior-knowledge mode per RFC 9113 Section 3.4 - it sends the HTTP/2 connection preface directly on the TCP connection, skipping TLS and ALPN negotiation entirely.

@example('protocols/http-client-h2c.php')

## Request Body Rules

TRACE requests that include a body are rejected with a `RequestException` per RFC 9110 Section 9.3.8. GET and HEAD requests are allowed to carry a body (useful for Elasticsearch-style query bodies), although this is not recommended per RFC 9110 Section 9.3.1.

@example('protocols/http-client-body-rules.php')

## Connection Timeout

`SendConfiguration::$connectionTimeout` sets the maximum duration for establishing a connection (TCP handshake + TLS handshake) on a per-request basis. When set, the connector's cancellation token is linked with a timeout token scoped to this duration. The overall request cancellation token still applies independently.

@example('protocols/http-client-connection-timeout.php')

## Callbacks

`SendConfiguration` provides two per-request callbacks for observing connection and protocol events:

- `onConnection(ConnectionMetadata): void` fires immediately after a connection is acquired (fresh or pooled), before the HTTP exchange. Use it to inspect the peer IP, local address, and TLS negotiation state.
- `onInformationalResponse(Response): void` fires for each 1xx response as it arrives (100 Continue, 103 Early Hints, etc.).

`ClientConfiguration` also accepts an `onInformationalResponse` callback that applies to all requests. When both the client-level and per-request callbacks are set, the client-level callback fires first, followed by the per-request callback.

@example('protocols/http-client-callbacks.php')

## Informational Responses

HTTP servers may send one or more 1xx informational responses before the final response. These are delivered in two ways:

1. The `onInformationalResponse` callback (on `ClientConfiguration` or `SendConfiguration`) fires as each 1xx response arrives.
2. All 1xx responses are collected in `Transaction::$informational` in chronological order.

Common informational responses include 100 Continue (the server is ready for the request body), 102 Processing (WebDAV, long-running operation), and 103 Early Hints (preliminary headers for resource preloading per RFC 8297).

@example('protocols/http-client-informational.php')

## Connection Metadata

`ConnectionMetadata` captures the transport-level details of a connection at acquisition time. It is available through the `onConnection` callback on `SendConfiguration`.

| Property | Type | Description |
|----------|------|-------------|
| `localAddress` | `Network\Address` | Local socket endpoint (IP and ephemeral port) |
| `peerAddress` | `Network\Address` | Resolved remote address after DNS resolution |
| `tlsState` | `TLS\ConnectionState` or `null` | TLS session details for HTTPS, `null` for plaintext |

The `TLS\ConnectionState` includes `version`, `cipherName`, `cipherBits`, `alpnProtocol`, `peerCertificate`, and `peerCertificateChain`.

## Configuration Merging

`SendConfiguration` fields override the client's `ClientConfiguration` on a per-request basis via `ClientConfiguration::withOverrides()`. Null fields in `SendConfiguration` inherit from the client default; non-null fields replace the client value for that request only.

The following fields are overridable per-request:

| Field | Overridable |
|-------|-------------|
| `maxResponseHeaderSize` | Yes |
| `maxResponseBodySize` | Yes |
| `baseUrl` | Yes |
| `tlsConfiguration` | Yes |
| `protocolVersions` | Yes |
| `proxyConfiguration` | Yes |
| `onInformationalResponse` | Yes (merged, not replaced) |
| `connectionTimeout` | Per-request only (not on `ClientConfiguration`) |
| `onConnection` | Per-request only (not on `ClientConfiguration`) |
| `socksConfiguration` | No (client-only) |
| `unixSocket` | No (client-only) |
| `h2ClientConfiguration` | No (client-only) |

> The `onInformationalResponse` callback receives special treatment: when both the client and send configuration define one, they are composed into a single callback that invokes the client-level callback first, then the per-request callback.

> All other overridable fields, including `proxyConfiguration`, are fully replaced by the per-request value when set. For example, setting `proxyConfiguration` on `SendConfiguration` completely overwrites the client-level proxy settings; they are not merged.

## Middleware

Connection-level middleware runs after the connection is established but before the HTTP exchange. Middleware has access to the `ConnectionInterface`, including the resolved peer address and TLS state. This enables security checks (SSRF protection), logging, metrics, and request/response transformation.

Middleware must do one of two things:

1. **Delegate**: Call `$handler->handle(...)` to continue the chain. The handler calls `finalize()` internally.
2. **Short-circuit**: Return a `Transaction` directly. When short-circuiting, you MUST call `$connection->finalize()` on the transaction before returning it. Without finalization, the underlying connection is never released back to the pool, causing a connection leak.

Middleware is registered via the `Client` constructor and applied in order: the first middleware in the list is the outermost (executed first).

@example('protocols/http-client-middleware.php')

`DeniedDestinationsMiddleware::forPrivateNetworkRanges()` also blocks current-network, shared, reserved, benchmarking, multicast, IPv6 translation, and 6to4 ranges. IPv4-mapped IPv6 addresses use the same checks as IPv4, including exact deny-list entries. Bracketed IPv6 peer addresses are accepted. Unix sockets have no peer IP and skip these checks.

## HTTP/1.0 Mode

Setting `protocolVersions` to `[ProtocolVersion::V10]` sends HTTP/1.0 requests. Connection: close is implicit per RFC 1945, so each request uses a separate TCP connection. Keep-alive and chunked transfer encoding are not available. Trailers are not supported.

@example('protocols/http-client-http10.php')

## Trailers

Request trailers are sent via `Request::$trailers`, an `Awaitable<FieldMap>` that resolves after the body. Response trailers are available via `Response::$trailers`, which resolves after the response body is fully consumed.

`Expect: 100-continue` waits up to one second before sending the body. The client accepts HTTP/1.0 and HTTP/1.1 interim responses, reads all their fields, and sends the body once. A final response received before the upload disables connection reuse.

HTTP/1 responses reject malformed Content-Length, whitespace before a field colon, obsolete folded fields, and unsupported protocol upgrades. At most 16 informational responses are accepted per exchange. Chunk size lines, extensions, and total trailers use `maxResponseHeaderSize`; CONNECT status lines and total headers each have an 8192-byte limit. I/O errors while reading a close-delimited body propagate to the caller. Responses with both Transfer-Encoding and Content-Length cannot reuse the connection.

HTTP/1 requests reject invalid method tokens, field names, and CR/LF in fields with `RequestException`. Explicit `Transfer-Encoding: chunked` uses chunk framing. A `Content-Length` must match the bytes read from the body. These checks also apply to `Expect: 100-continue`.

Trailers require chunked transfer encoding (HTTP/1.1) or HTTP/2 DATA frames. They are not available with HTTP/1.0. Declare expected trailer field names using the `Trailer` header.

@example('protocols/http-client-trailers.php')

## Connection Pooling

The default `PooledConnector` manages connection reuse across requests:

Pool entries also require matching SNI hosts, TLS settings, HTTP and SOCKS proxy settings, Unix socket paths, resolved HTTP versions, and H2 settings. Requests with different transport or security settings cannot share a connection. Unix H2 sessions reuse connections only within the same pool entry.

- **HTTP/1.x**: Idle connections are pooled per origin (scheme + host + port) with LIFO checkout. When a request completes and the body is fully consumed, the underlying TCP/TLS stream is returned to the pool. A global cap of 256 idle connections and a per-host cap of 32 prevent unbounded memory growth.
- **HTTP/2**: A single multiplexed connection per origin is shared across concurrent requests. Each request opens one HTTP/2 stream on the shared connection. If the connection is closed (GOAWAY frame or connection error), it is removed and a new connection is established on the next request.
- **Connection coalescing**: When multiple fibers concurrently request connections to the same HTTPS origin, the first fiber performs the TLS handshake. If HTTP/2 is negotiated, waiting fibers reuse the session instead of opening redundant connections. If HTTP/1.1 is negotiated, waiting fibers establish their own connections.

## Pool Release and Finalize

For HTTP/1.x keep-alive connections, the pooled connection is not released until the response body is fully consumed. The response body handle defers the pool release until EOF or close. This prevents a second request from reusing a connection while the first response body is still being read.

This is why middleware that short-circuits the handler chain MUST call `$connection->finalize()`: finalize wraps the response body (if any) so the connection is properly returned to the pool. Without it, the connection leaks.

For HTTP/2 connections, stream lifecycle is managed independently, so finalize returns the transaction as-is.

## Error Handling

The client throws a structured exception hierarchy. Transport-level exceptions from `Psl\Network` and `Psl\IO` propagate unwrapped.

@example('protocols/http-client-errors.php')

## Exception Hierarchy

| Exception | When Thrown |
|-----------|------------|
| `Exception\RuntimeException` | Base class for all HTTP client errors |
| `Exception\RequestException` | Request is invalid (missing URL, malformed target, TRACE with body) |
| `Exception\ProtocolException` | Malformed response, unsupported protocol, body size exceeded |
| `Exception\TooManyRedirectsException` | Redirect limit exceeded (via `RedirectClient`) |
| `Network\Exception\RuntimeException` | Connection refused, DNS failure, connect timeout |
| `IO\Exception\RuntimeException` | Read/write failure on the underlying socket |
| `Async\Exception\CancelledException` | Cancellation token fired during any stage |

## Protocol Support

| Version | Transport | Negotiation | Multiplexing | Status |
|---------|-----------|-------------|--------------|--------|
| HTTP/1.0 | TCP / TLS | Explicit | No | Supported |
| HTTP/1.1 | TCP / TLS | Default fallback | No (persistent connections) | Supported |
| HTTP/2 | TLS (h2) / TCP (h2c) | ALPN | Yes (streams over single connection) | Supported |
| HTTP/3 | QUIC | ALPN | Yes (independent streams, no HOL blocking) | Planned |

> HTTP/3 support is not yet available. The client's connector and connection abstractions are designed to accommodate QUIC-based transports in the future without breaking the public API.

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 1945 | HTTP/1.0 message format |
| RFC 9110 | HTTP semantics, methods, status codes, redirects, idempotency |
| RFC 9112 | HTTP/1.1 message syntax, chunked transfer encoding, persistent connections |
| RFC 9113 | HTTP/2 binary framing, HPACK, flow control, server push, multiplexing |
| RFC 9114 | HTTP/3 over QUIC (planned, not yet implemented) |
| RFC 8297 | 103 Early Hints informational responses |
| RFC 3986 | URI resolution for base URL and redirect Location headers |
| RFC 1928 | SOCKS5 proxy protocol |
| RFC 7231 | Historical redirect method rewriting (301/302 to GET) |

See `src/Psl/HTTP/Client/` for the full API.
