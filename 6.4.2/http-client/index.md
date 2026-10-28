# HTTP Client

The `HTTP Client` component provides an async HTTP client with connection pooling, HTTP/2 multiplexing, automatic protocol negotiation via ALPN, middleware support, and composable decorators for redirects and retries. All I/O is non-blocking and built on the PSL async runtime.

## Basic Usage

Create a client, build a request, and send it. The returned transaction contains the response with status, headers, and a streaming body.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\Client();

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));

$tx = $client->send($request);

$tx->response->status;
$tx->response->protocolVersion;
$tx->response->headers->get('content-type');
$tx->response->body?->readAll();
```

## Sending Data

Send a POST request with a JSON body and custom headers using `IO\MemoryHandle` for the request body.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$client = new Client\Client();

$request = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://httpbin.org/post'),
    headers: Message\FieldMap::from([
        ['Content-Type',  'application/json'],
        ['Accept',        'application/json'],
        ['Authorization', 'Bearer tok_example'],
    ]),
    body: new IO\MemoryHandle('{"name": "Alice", "email": "alice@example.com"}'),
);

$tx = $client->send($request);

$tx->response->status;
$tx->response->body?->readAll();
```

## Concurrent Requests

An HTTP/2 stream error fails only that request. Other requests on the same connection keep running, and the client can reuse the session.

Use `Async\concurrently()` to send multiple requests in parallel over the same client. HTTP/2 connections are automatically multiplexed.

```php
use Psl\Async;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// HTTP/2 queues requests at the peer limit until an active stream closes.
// Cancellation interrupts these waits; close an unread body to cancel its stream.

$client = new Client\Client();

$transactions = Async\concurrently([
    'users' => static fn() => $client->send(new Message\Request(
        method: Message\METHOD_GET,
        url: URL\parse('https://httpbin.org/get?resource=users'),
    )),
    'posts' => static fn() => $client->send(new Message\Request(
        method: Message\METHOD_GET,
        url: URL\parse('https://httpbin.org/get?resource=posts'),
    )),
    'comments' => static fn() => $client->send(new Message\Request(
        method: Message\METHOD_GET,
        url: URL\parse('https://httpbin.org/get?resource=comments'),
    )),
]);

$transactions['users']->response->status;
$transactions['posts']->response->body?->readAll();
```

## Redirects

`RedirectClient` wraps any client and automatically follows 3xx redirects with credential stripping on cross-origin hops and method rewriting per RFC 9110.

Redirects that preserve the request body, including 307 and 308, resend it. A seekable body is restored to its original position before each resend. A non-seekable body is resent only if it was never read, as when an `Expect: 100-continue` upload is pre-empted by the redirect; once read, it throws `Exception\RequestException`. A failure reading the body's position is also reported as `Exception\RequestException`.

Location values follow RFC 3986 resolution. A URL inside a query or fragment does not make the reference absolute. A scheme without an authority, such as `https:login`, is rejected as an invalid URL. Only `http` and `https` targets are followed; a redirect to any other scheme, such as `ftp://`, throws `Exception\ProtocolException`.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

// Location values such as /login?next=https://x resolve against the current URL.
$client = new Client\RedirectClient(new Client\Client(), maxRedirects: 5, autoReferrer: true);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://httpbin.org/redirect/3'));

$tx = $client->send($request);

$tx->response->status;

// A seekable body lets RedirectClient resend it after a 307 or 308 response.
$upload = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://httpbin.org/redirect-to?url=/post&status_code=307'),
    body: new IO\MemoryHandle('payload'),
);

$client->send($upload);

// 301 and 302 preserve PUT and its body; only POST changes to GET.
$client->send(new Message\Request(
    method: Message\METHOD_PUT,
    url: URL\parse('https://httpbin.org/redirect-to?url=/put&status_code=302'),
    body: new IO\MemoryHandle('payload'),
));
```

For 301 and 302, only POST changes to GET. Other methods keep their body and method. For 303, all methods except GET and HEAD change to GET. A body that survives a redirect must be seekable so the client can return to its original position.

## Retries

`RetryClient` wraps any client and retries idempotent requests on transport-level failures with exponential backoff. Failures implementing `Network\Exception\NonRetryableExceptionInterface`, such as a rejected TLS certificate (`TLS\Exception\PeerVerificationException`), are rethrown at once.

```php
use Psl\DateTime\Duration;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\RetryClient(
    new Client\RedirectClient(new Client\Client()),
    maxAttempts: 3,
    backoff: Duration::milliseconds(200),
    backoffMultiplier: 2,
);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com/api/data'));

$tx = $client->send($request);

$tx->response->status;
```

## Configuration

`ClientConfiguration` controls TLS settings, protocol version preferences, response size limits, base URL, and proxy configuration.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\TLS;
use Psl\URL;

$configuration = new Client\ClientConfiguration(
    maxResponseHeaderSize: 16_384,
    // HTTP/2 enforces this limit as DATA arrives, even before the body is read.
    maxResponseBodySize: 50_000_000,
    baseUrl: URL\parse('https://api.example.com/v2'),
    tlsConfiguration: new TLS\ClientConfiguration(minimumVersion: TLS\Version::Tls12),
    protocolVersions: [ProtocolVersion::V20, ProtocolVersion::V11],
);

// The pool keeps connections with different TLS or proxy settings separate.
$client = new Client\Client(configuration: $configuration);

$request = new Message\Request(method: Message\METHOD_GET, url: null, requestTarget: '/users');

$tx = $client->send($request);

$tx->response->status;
```

## Per-Request Overrides

`SendConfiguration` overrides specific client settings for a single request without affecting the client default.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\URL;

$client = new Client\Client();

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com/large-file.tar.gz'));

$tx = $client->send(
    $request,
    new Client\SendConfiguration(maxResponseBodySize: 500_000_000, protocolVersions: [ProtocolVersion::V11]),
);

$tx->response->status;
$tx->response->body?->readAll();
```

## SSRF Protection

`DeniedDestinationsMiddleware` inspects the resolved peer IP after connection establishment and blocks requests to private, loopback, and link-local addresses.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// The preset also blocks shared, reserved, documentation, multicast, translation,
// 6to4, Teredo, discard-only, and deprecated site-local ranges. Unix sockets have
// no peer IP and do not undergo these checks. Behind a proxy the check sees the proxy.
$client = new Client\Client(middleware: [Client\Middleware\DeniedDestinationsMiddleware::forPrivateNetworkRanges()]);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));
$tx = $client->send($request);

// IPv4-mapped IPv6 receives the same checks as its IPv4 address.
$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('http://[::ffff:169.254.169.254]/metadata'));
$tx = $client->send($request);
```

## Proxy Modes

The client supports three proxy modes, configured via `ClientConfiguration` or overridden per-request via `SendConfiguration`:

- **No proxy** (default): The client connects directly to the target server.
- **HTTP proxy** (`$proxyConfiguration`): Configured via a `ProxyConfiguration` object that holds the proxy URL, optional `Proxy-Authorization` header, TLS SNI hostname, and bypass list. For HTTPS targets, the client connects to the proxy and issues an HTTP/1.1 CONNECT request to create a tunnel. For plain HTTP targets, the client connects to the proxy directly and uses absolute-form request targets per RFC 7230 Section 5.3.2 (forward proxying). The `$skipProxyFor` list on the configuration specifies hosts that bypass the proxy entirely.
- **SOCKS5 proxy** (`$socksConfiguration`): All TCP connections are routed through the SOCKS5 proxy server. TLS, ALPN negotiation, and HTTP framing happen on top of the tunneled connection unchanged.

Both proxy types can be combined: when both `$socksConfiguration` and `$proxyConfiguration` are set, the SOCKS5 proxy tunnels the TCP connection to the HTTP proxy, which then handles HTTP forward proxying or CONNECT tunneling.

## SOCKS Proxy

Route all TCP connections through a SOCKS5 proxy using `$socksConfiguration`. TLS and ALPN negotiation happen on top of the tunneled connection.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\Socks;
use Psl\URL;

$configuration = new Client\ClientConfiguration(socksConfiguration: new Socks\Configuration(
    proxyHost: 'proxy.example.com',
    proxyPort: 1080,
    username: 'user',
    password: 'secret', // @mago-expect lint:no-literal-password
));

$client = new Client\Client(configuration: $configuration);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));

$tx = $client->send($request);

$tx->response->status;
```

## HTTP Proxy

Use `$proxyConfiguration` to route requests through an HTTP proxy. Configure the proxy URL, authentication, and bypass rules via `ProxyConfiguration`. HTTPS targets use CONNECT tunneling while plain HTTP targets use forward proxying with absolute-form request targets.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$configuration = new Client\ClientConfiguration(
    proxyConfiguration: new Client\ProxyConfiguration(
        URL\parse('http://proxy.example.com:8080'),
        skipProxyFor: [
            'localhost',
            '.internal.example.com',
        ],
    ),
);

// CONNECT leaves all bytes after the proxy headers for the tunnel.
$client = new Client\Client(configuration: $configuration);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));
$tx = $client->send($request);

$tx->response->status;
```

CONNECT reads stop at the end of the proxy headers, leaving any following tunnel bytes on the stream. The CONNECT status code must be exactly three digits followed by a space or the line end, so a value such as `2000` is rejected rather than read as `200`.

## HTTP/2 over Plaintext (h2c)

For HTTP/2 requests, the client drops `connection`, `keep-alive`, `proxy-connection`, `transfer-encoding`, `upgrade`, and each field named in `Connection`. It maps the host to `:authority` and keeps `te` only when its value is `trailers` (case-insensitive). The same filter applies to request trailers. Invalid initial field values fail the request before its HEADERS are sent and leave the session usable. Invalid trailers reset only their stream, so other requests can still use the session.

For servers that support HTTP/2 without TLS, set `protocolVersions` to only `ProtocolVersion::V20`. The client uses prior-knowledge mode per RFC 9113 Section 3.4 - it sends the HTTP/2 connection preface directly on the TCP connection, skipping TLS and ALPN negotiation entirely.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\Client(
    configuration: new Client\ClientConfiguration(protocolVersions: [Message\ProtocolVersion::V20]),
);

// A failed exchange is retried at most once, and only if the body is absent or rewinds.
// REFUSED_STREAM or a GOAWAY above the stream retries any method (provably unprocessed);
// a connection-level failure before any response retries GET/HEAD/OPTIONS/TRACE or an
// Idempotency-Key request.
$tx = $client->send(new Message\Request(method: Message\METHOD_GET, url: URL\parse('http://localhost:8080/')));

$tx->response->protocolVersion; // ProtocolVersion::V20
$tx->response->status; // 200
$tx->response->body?->readAll();
```

## Request Body Rules

TRACE requests that include a body are rejected with a `RequestException` per RFC 9110 Section 9.3.8. GET and HEAD requests are allowed to carry a body (useful for Elasticsearch-style query bodies), although this is not recommended per RFC 9110 Section 9.3.1.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$client = new Client\Client();

// TRACE requests with a body throw RequestException per RFC 9110 Section 9.3.8
try {
    $client->send(new Message\Request(
        method: Message\METHOD_TRACE,
        url: URL\parse('https://example.com'),
        body: new IO\MemoryHandle('trace body'),
    ));
} catch (Client\Exception\RequestException $e) {
    // "TRACE requests must not include a body."
    $e->getMessage();
}

// TRACE without a body is allowed
$tx = $client->send(new Message\Request(method: Message\METHOD_TRACE, url: URL\parse('https://example.com')));

// GET with a body is allowed (useful for Elasticsearch-style query bodies)
// but not recommended per RFC 9110 Section 9.3.1
$tx = $client->send(new Message\Request(
    method: Message\METHOD_GET,
    url: URL\parse('https://elasticsearch.example.com/_search'),
    headers: new Message\FieldMap([['content-type', 'application/json']]),
    body: new IO\MemoryHandle('{"query":{"match_all":{}}}'),
));

$tx->response->status;
$tx->response->body?->readAll();
```

## Connection Timeout

`SendConfiguration::$connectionTimeout` sets the maximum duration for establishing a connection (TCP handshake + TLS handshake) on a per-request basis. When set, the connector's cancellation token is linked with a timeout token scoped to this duration. The overall request cancellation token still applies independently.

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\Client();

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));

// Limit the connection phase (TCP + TLS handshake) to 5 seconds.
// The overall request cancellation token still applies independently.
try {
    $tx = $client->send($request, new Client\SendConfiguration(connectionTimeout: Duration::seconds(5)));

    $tx->response->status; // 200
} catch (Async\Exception\CancelledException) {
    // @mago-expect lint:no-empty-catch-clause - Connection took longer than 5 seconds
}
```

## Callbacks

`SendConfiguration` provides two per-request callbacks for observing connection and protocol events:

- `onConnection(ConnectionMetadata): void` fires immediately after a connection is acquired (fresh or pooled), before the HTTP exchange. Use it to inspect the peer IP, local address, and TLS negotiation state.
- `onInformationalResponse(Response): void` fires for each 1xx response as it arrives (100 Continue, 103 Early Hints, etc.).

`ClientConfiguration` also accepts an `onInformationalResponse` callback that applies to all requests. When both the client-level and per-request callbacks are set, the client-level callback fires first, followed by the per-request callback.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\Client();

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));

$tx = $client->send(
    $request,
    new Client\SendConfiguration(
        onConnection: static function (ConnectionMetadata $metadata): void {
            // Peer address is the resolved IP after DNS resolution
            $metadata->peerAddress->host; // e.g. "93.184.216.34"
            $metadata->peerAddress->port; // e.g. 443

            // Local address is the ephemeral socket on this machine
            $metadata->localAddress->host; // e.g. "192.168.1.100"
            $metadata->localAddress->port; // e.g. 52431

            // TLS state is available for HTTPS connections
            if ($metadata->tlsState !== null) {
                $metadata->tlsState->version; // e.g. TLS\Version::Tls13
                $metadata->tlsState->cipherName; // e.g. "TLS_AES_256_GCM_SHA384"
                $metadata->tlsState->alpnProtocol; // e.g. "h2"
                $metadata->tlsState->peerCertificate; // server certificate
            }
        },
        onInformationalResponse: static function (Message\Response $response): void {
            // Fires for each 1xx response (100 Continue, 103 Early Hints, etc.)
            $response->status; // e.g. 103
            $response->headers->getAll('link'); // e.g. ["</style.css>; rel=preload; as=style"]
        },
    ),
);

$tx->response->status;
```

## Informational Responses

HTTP servers may send one or more 1xx informational responses before the final response. These are delivered in two ways:

1. The `onInformationalResponse` callback (on `ClientConfiguration` or `SendConfiguration`) fires as each 1xx response arrives.
2. All 1xx responses are collected in `Transaction::$informational` in chronological order.

Common informational responses include 100 Continue (the server is ready for the request body), 102 Processing (WebDAV, long-running operation), and 103 Early Hints (preliminary headers for resource preloading per RFC 8297).

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// Client-level callback fires for all requests
$client = new Client\Client(
    configuration: new Client\ClientConfiguration(onInformationalResponse: static function (Message\Response $response): void {
        if ($response->status === 103) {
            // Extract Link headers from 103 Early Hints for resource preloading
            foreach ($response->headers->getAll('link') as $link) {
                // e.g. "</style.css>; rel=preload; as=style"
                // Begin preloading the linked resource
                $link;
            }
        }
    }),
);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));

// Per-request callback fires after the client-level callback
$tx = $client->send($request, new Client\SendConfiguration(onInformationalResponse: static function (Message\Response $response): void {
    // This fires after the client-level callback for each 1xx response
    if ($response->status === 100) {
        // HTTP/1 limits each exchange to 16 informational responses.
        // Send the body after 100 Continue, or after a one-second wait.
    }
}));

// Informational responses are also collected in the transaction
foreach ($tx->informational as $info) {
    $info->status; // 100, 102, 103, etc.
    $info->headers; // headers from the informational response
}

$tx->response->status; // final 2xx-5xx response
```

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
| `expectContinueTimeout` | No (client-only) |

> The `onInformationalResponse` callback receives special treatment: when both the client and send configuration define one, they are composed into a single callback that invokes the client-level callback first, then the per-request callback.

> All other overridable fields, including `proxyConfiguration`, are fully replaced by the per-request value when set. For example, setting `proxyConfiguration` on `SendConfiguration` completely overwrites the client-level proxy settings; they are not merged.

## Middleware

Connection-level middleware runs after the connection is established but before the HTTP exchange. Middleware has access to the `ConnectionInterface`, including the resolved peer address and TLS state. This enables security checks (SSRF protection), logging, metrics, and request/response transformation.

Middleware must do one of two things:

1. **Delegate**: Call `$handler->handle(...)` to continue the chain. The handler calls `finalize()` internally.
2. **Short-circuit**: Return a `Transaction` directly. When short-circuiting, you MUST call `$connection->finalize()` on the transaction before returning it. Without finalization, the underlying connection is never released back to the pool, causing a connection leak.

Middleware is registered via the `Client` constructor and applied in order: the first middleware in the list is the outermost (executed first).

```php
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DateTime;
use Psl\HTTP\Client;
use Psl\HTTP\Client\Connection\ConnectionInterface;
use Psl\HTTP\Client\Handler\HandlerInterface;
use Psl\HTTP\Client\Middleware\MiddlewareInterface;
use Psl\HTTP\Message;
use Psl\URL;

// Middleware that delegates to the next handler (pass-through with logging)
final readonly class TimingMiddleware implements MiddlewareInterface
{
    public function process(
        ConnectionInterface $connection,
        Message\Request $request,
        Client\ClientConfiguration $configuration,
        HandlerInterface $handler,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Message\Transaction {
        $start = DateTime\Timestamp::monotonic();

        // Delegate to the next handler. The handler calls finalize() internally.
        $transaction = $handler->handle($connection, $request, $configuration, $cancellation);

        $elapsed = DateTime\Timestamp::monotonic()->since($start)->toString();
        // Log: "GET https://example.com completed in 42.5ms"

        return $transaction;
    }
}

// Middleware that short-circuits the chain (returns a cached response)
final readonly class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        /** @var array<string, Message\Response> */
        private array $cache = [],
    ) {}

    public function process(
        ConnectionInterface $connection,
        Message\Request $request,
        Client\ClientConfiguration $configuration,
        HandlerInterface $handler,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Message\Transaction {
        $cacheKey = $request->method . ':' . $request->requestTarget;

        if (isset($this->cache[$cacheKey])) {
            // Short-circuit: MUST call finalize() to release the connection back to the pool
            return $connection->finalize(new Message\Transaction([], null, $this->cache[$cacheKey]));
        }

        // No cache hit, delegate to the next handler
        return $handler->handle($connection, $request, $configuration, $cancellation);
    }
}

$client = new Client\Client(middleware: [
    new TimingMiddleware(),
    new CacheMiddleware(),
]);

$tx = $client->send(new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com')));

$tx->response->status;
```

`DeniedDestinationsMiddleware::forPrivateNetworkRanges()` also blocks current-network, shared, reserved, benchmarking, documentation (IPv4 TEST-NET-1/2/3 and IPv6 2001:db8::/32, 3fff::/20), multicast, IPv6 translation and 6to4, Teredo, discard-only, IPv4-compatible, and deprecated site-local ranges. IPv4-mapped IPv6 addresses use the same checks as IPv4, including exact deny-list entries. Bracketed IPv6 peer addresses are accepted. Unix sockets have no peer IP and skip these checks. The check inspects the resolved peer address, so behind an HTTP or SOCKS proxy it sees the proxy's address, not the ultimate target.

## HTTP/1.0 Mode

Setting `protocolVersions` to `[ProtocolVersion::V10]` sends HTTP/1.0 requests. Connection: close is implicit per RFC 1945, so each request uses a separate TCP connection. Keep-alive and chunked transfer encoding are not available. Trailers are not supported.

```php
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// Force HTTP/1.0 for legacy server compatibility.
// Connection: close is implicit; no keep-alive, no chunked transfer encoding.
$client = new Client\Client(
    configuration: new Client\ClientConfiguration(protocolVersions: [Message\ProtocolVersion::V10]),
);

$tx = $client->send(new Message\Request(
    method: Message\METHOD_GET,
    url: URL\parse('http://legacy-server.example.com/status'),
));

$tx->response->protocolVersion; // ProtocolVersion::V10
$tx->response->status;
$tx->response->body?->readAll();
```

## Trailers

Request trailers are sent via `Request::$trailers`, an `Awaitable<FieldMap>` that resolves after the body. Response trailers are available via `Response::$trailers`, which resolves after the response body is fully consumed.

`Expect: 100-continue` waits for an interim response before sending the body, up to `ClientConfiguration::$expectContinueTimeout` (one second by default). The client accepts HTTP/1.0 and HTTP/1.1 interim responses, reads all their fields, and sends the body once. A final response received within the wait suppresses the body and disables connection reuse.

HTTP/1 responses reject malformed Content-Length, whitespace before a field colon, obsolete folded fields, unsupported protocol upgrades, and a bare CR, LF or NUL in a header or trailer value. At most 16 informational responses are accepted per exchange. Chunk size lines, extensions, and total trailers use `maxResponseHeaderSize`; CONNECT status lines and total headers each have an 8192-byte limit. I/O errors while reading a close-delimited body propagate to the caller. Every `Connection` field is read, so a `close` token in any of them ends reuse. A connection cannot be reused when a response carries both Transfer-Encoding and Content-Length, or when an HTTP/1.0 response used Transfer-Encoding.

HTTP/1 requests reject, with `RequestException`, a method that is not a token, a request target containing spaces, tabs, control bytes or non-ASCII, a field name that is not a token, a field value with control bytes other than HTAB, and more than one Host field. Explicit `Transfer-Encoding: chunked` uses chunk framing. A `Content-Length` must match the bytes read from the body. HTTP/1.0 never gets chunked framing added: a body there must declare a Content-Length, and Transfer-Encoding and trailers are rejected. Trailers also require chunked framing under HTTP/1.1, so they are rejected alongside a Content-Length. The same method, target and Content-Length checks run on the HTTP/2 path, and HTTP/2 field names and values are validated as they are encoded, so a request cannot smuggle framing through an HTTP/2-to-HTTP/1 downgrade. These checks also apply to `Expect: 100-continue`.

Trailers require chunked transfer encoding (HTTP/1.1) or HTTP/2 DATA frames. They are not available with HTTP/1.0. Declare expected trailer field names using the `Trailer` header.

```php
use Psl\Async;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$client = new Client\Client();

// Sending request trailers using an Awaitable<FieldMap>.
// Invalid trailer names and CR/LF in fields throw RequestException in HTTP/1.
// Trailers are sent after the body with chunked transfer encoding (H1) or after DATA frames (H2).
$deferred = new Async\Deferred();
$request = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://example.com/upload'),
    headers: new Message\FieldMap([
        ['content-type', 'application/octet-stream'],
        ['trailer',      'checksum'],
    ]),
    body: new IO\MemoryHandle('file contents here'),
    trailers: $deferred->getAwaitable(),
);

// Resolve the trailers after the body is ready to be sent.
// In practice, you would compute the checksum while streaming the body.
$deferred->complete(new Message\FieldMap([['checksum', 'sha256=abc123...']]));

$tx = $client->send($request);

// Reading response trailers. Trailers are available after the body is fully consumed.
$body = $tx->response->body?->readAll();

if ($tx->response->trailers !== null) {
    $trailerFields = $tx->response->trailers->await();
    $trailerFields->get('server-timing'); // e.g. "db;dur=53"
}
```

## Connection Pooling

The default `PooledConnector` manages connection reuse across requests:

Pool entries also require matching SNI hosts, TLS settings, HTTP and SOCKS proxy settings, Unix socket paths, resolved HTTP versions, and H2 settings. Requests with different transport or security settings cannot share a connection. Unix H2 sessions reuse connections only within the same pool entry.

- **HTTP/1.x**: Idle connections are pooled per origin (scheme + host + port) with LIFO checkout. When a request completes and the body is fully consumed, the underlying TCP/TLS stream is returned to the pool. A global cap of 256 idle connections and a per-host cap of 32 prevent unbounded memory growth.
- **HTTP/2**: A single multiplexed connection per origin is shared across concurrent requests. Each request opens one HTTP/2 stream on the shared connection. If the connection is closed (GOAWAY frame or connection error), it is removed and a new connection is established on the next request.
- **Connection coalescing**: When multiple fibers concurrently request connections to the same HTTPS origin, the first fiber performs the TLS handshake. If HTTP/2 is negotiated, waiting fibers reuse the session instead of opening redundant connections. If HTTP/1.1 is negotiated, waiting fibers establish their own connections.

HTTP/2 retries a failed exchange at most once, and only when the body is absent or rewinds to its original position. Two cases qualify. First, when the peer proves it did not process the request: REFUSED_STREAM, a stream above GOAWAY's last-stream-id, or no HEADERS written yet. Any method, including POST, retries then (RFC 9113 Section 8.7). Second, when the request is replayable, meaning GET, HEAD, OPTIONS or TRACE or an `Idempotency-Key` header, and the session failed at the connection level before any response headers arrived, such as a closed connection, an I/O error, or a GOAWAY followed by close (RFC 9110 Section 9.2.2, matching Go `net/http`). A stream reset with a code other than REFUSED_STREAM, and any failure after response headers arrive, never replay.

HTTP/2 limits concurrent outgoing streams to the smaller of the client's `maxConcurrentStreams` and the peer's SETTINGS_MAX_CONCURRENT_STREAMS. The client's own value applies from the first request; the peer's lowers it once received. A stream holds its slot until it closes, including while its response body is still arriving.

HTTP/2 checks `maxResponseBodySize` as DATA frames arrive and cancels a stream that exceeds it, even if its body is unread. The same limit bounds each stream's buffer, and a value of `0` means unlimited, as for HTTP/1. Flow-control windows still update on receipt because the h2 API cannot defer updates until reads. A `:status` that is not three digits in 100-999 is a stream error, and at most 16 interim (1xx) responses are accepted per stream, as for HTTP/1.

Cancellation interrupts stream-slot waits and uploads, including stalled flow-control windows. Cancelling a request or closing its response body early sends RST_STREAM(CANCEL) and frees its stream slot. A request waiting for a pooled connection can cancel its own wait without cancelling the shared connection attempt.

## Pool Release and Finalize

For HTTP/1.x keep-alive connections, the pooled connection is not released until the response body is fully consumed. The response body handle defers the pool release until EOF or close. This prevents a second request from reusing a connection while the first response body is still being read.

This is why middleware that short-circuits the handler chain MUST call `$connection->finalize()`: finalize wraps the response body (if any) so the connection is properly returned to the pool. Without it, the connection leaks.

For HTTP/2 connections, stream lifecycle is managed independently, so finalize returns the transaction as-is.

## Error Handling

The client throws a structured exception hierarchy. Transport-level exceptions from `Psl\Network` and `Psl\IO` propagate unwrapped.

```php
use Psl\Async;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\Network;
use Psl\URL;

$client = new Client\RedirectClient(new Client\Client());

try {
    $tx = $client->send(new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com')));

    $tx->response->status;
} catch (Client\Exception\RequestException $e) {
    IO\write_line('Invalid request: %s', $e->getMessage());
} catch (Client\Exception\ProtocolException $e) {
    // HTTP/1 status codes must have exactly three digits in the range 100-999.
    IO\write_line('Bad response: %s', $e->getMessage());
} catch (Client\Exception\TooManyRedirectsException $e) {
    IO\write_line('Redirect loop: %s', $e->getMessage());
} catch (Network\Exception\RuntimeException $e) {
    IO\write_line('Connection failed: %s', $e->getMessage());
} catch (IO\Exception\RuntimeException $e) {
    IO\write_line('I/O error: %s', $e->getMessage());
} catch (Async\Exception\CancelledException) {
    IO\write_line('Request cancelled');
}
```

HTTP/1 status codes must contain exactly three digits and fall within 100-999; codes 600-999 remain accepted.

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
| RFC 9110 | Redirect method rewriting (301/302 POST to GET, 303 to GET except HEAD) |

See [src/Psl/HTTP/Client/](https://github.com/php-standard-library/php-standard-library/tree/6.4.2/packages/http-message/src/Psl/HTTP/Client/) for the full API.
