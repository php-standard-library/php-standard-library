# HTTP Message

The `HTTP Message` component provides version-agnostic HTTP message abstractions. Requests, responses, and headers work identically across HTTP/1.0, HTTP/1.1, HTTP/2, and HTTP/3. Bodies are streaming (`ReadHandleInterface`), trailers are async (`Awaitable<FieldMap>`), and all objects are immutable.

## Requests

Construct a request with a method, URL, and optional headers/body. The request target is derived from the URL automatically.

```php
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com/api/users?page=1'));

$request->method; // "GET"
$request->requestTarget; // "/api/users?page=1"
$request->url?->authority->host; // "example.com"
$request->protocolVersion; // ProtocolVersion::V11

$post = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://example.com/api/users'),
    headers: Message\FieldMap::from([
        ['Content-Type', 'application/json'],
        ['Accept',       'application/json'],
    ]),
    body: new IO\MemoryHandle('{"name": "Alice"}'),
);
```

## Responses

Responses carry a status code, headers, and an optional streaming body. Reason phrases are not stored on the response since HTTP/2 and HTTP/3 do not transmit them.

```php
use Psl\HTTP\Message;
use Psl\IO;

$response = new Message\Response(
    status: Message\STATUS_OK,
    headers: Message\FieldMap::from([
        ['Content-Type', 'text/html; charset=utf-8'],
    ]),
    body: new IO\MemoryHandle('<h1>Hello</h1>'),
);

$response->status; // 200
$response->headers->get('Content-Type'); // "text/html; charset=utf-8"

Message\reason_phrase($response->status); // "OK"
Message\reason_phrase(Message\STATUS_NOT_FOUND); // "Not Found"
```

## Header Fields

`FieldMap` is an ordered, case-insensitive collection of header field pairs. It supports multiple values for the same name (e.g. Set-Cookie) and preserves insertion order and original casing.

```php
use Psl\HTTP\Message\FieldMap;
use Psl\IO;

$headers = FieldMap::from([
    ['Content-Type', 'application/json'],
    ['Accept',       'text/html'],
    ['Accept',       'application/json'],
    ['X-Request-Id', 'abc-123'],
]);

$headers->get('content-type'); // "application/json" (case-insensitive)
$headers->getAll('Accept'); // ["text/html", "application/json"]
$headers->has('X-Request-Id'); // true

$updated = $headers
    ->with('Content-Type', 'text/plain')
    ->withAdded('Cache-Control', 'no-cache')
    ->without('X-Request-Id');

$updated->get('Content-Type'); // "text/plain"
$updated->has('X-Request-Id'); // false

foreach ($headers as [$name, $value]) {
    // Iterates in insertion order, preserving original casing
    IO\write_line('%s: %s', $name, $value);
}
```

## Immutable Mutation

All `with*()` methods return new instances. The original message is never modified.

```php
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com/'));

$modified = $request
    ->withMethod(Message\METHOD_POST)
    ->withUrl(URL\parse('https://example.com/api/submit'))
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('Authorization', 'Bearer token123')
    ->withBody(new IO\MemoryHandle('{"data": true}'))
    ->withProtocolVersion(Message\ProtocolVersion::V20);

$modified->method; // "POST"
$modified->protocolVersion; // ProtocolVersion::V20

$request->method; // "GET" (original unchanged)
```

## Streaming Bodies

Bodies are modeled as `ReadHandleInterface` streams, allowing constant-memory processing of arbitrarily large payloads. Use `MemoryHandle` to create a body from a string.

```php
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$request = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://example.com/upload'),
    headers: Message\FieldMap::from([
        ['Content-Type', 'application/json'],
    ]),
    body: new IO\MemoryHandle('{"name": "Alice", "role": "admin"}'),
);

$body = $request->body;
$body?->tryRead(16); // '{"name": "Alice"' (non-blocking, up to 16 bytes)
$body?->read(maxBytes: 8); // ', "role"' (suspends until data available)

$response = new Message\Response(
    status: Message\STATUS_OK,
    body: new IO\MemoryHandle('<html><body>Hello, world!</body></html>'),
);

$response->body?->readAll(); // '<html><body>Hello, world!</body></html>'
```

## Trailers

Trailing header fields arrive after the body has been fully transmitted. They are modeled as `Awaitable<FieldMap>` because the values are not known until the body is complete. Use `Deferred` to create trailers that are resolved later.

```php
use Psl\Async;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

/** @var Async\Deferred<Message\FieldMap> $deferred */
$deferred = new Async\Deferred();
$request = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://example.com/upload'),
    headers: Message\FieldMap::from([
        ['Trailer', 'Checksum'],
    ]),
    body: new IO\MemoryHandle('file contents here'),
    trailers: $deferred->getAwaitable(),
);

$deferred->complete(Message\FieldMap::from([
    ['Checksum', 'sha256=abc123...'],
]));

$trailerMap = $request->trailers?->await();
$trailerMap?->get('Checksum'); // "sha256=abc123..."
```

## Transactions

`Transaction` groups the final response with any informational (1xx) responses received before it and any HTTP/2 server-pushed exchanges. This is the return type of connection-level exchange methods.

```php
use Psl\HTTP\Message;
use Psl\IO;

$earlyHints = new Message\Response(status: Message\STATUS_EARLY_HINTS, headers: Message\FieldMap::from([
    ['Link', '</style.css>; rel=preload; as=style'],
]));

$finalResponse = new Message\Response(
    status: Message\STATUS_OK,
    headers: Message\FieldMap::from([
        ['Content-Type', 'text/html'],
    ]),
    body: new IO\MemoryHandle('<h1>Hello</h1>'),
);

$tx = new Message\Transaction(informational: [$earlyHints], pushed: null, response: $finalResponse);

$tx->response->status; // 200
$tx->informational[0]->status; // 103
$tx->informational[0]->headers->get('Link'); // "</style.css>; rel=preload; as=style"
$tx->pushed; // null (no HTTP/2 server push)
```

## Status Codes and Methods

Method and status code constants improve readability and prevent typos. The `reason_phrase()` function maps any status code to its canonical reason phrase for HTTP/1.x serialization.

```php
use Psl\HTTP\Message;

Message\METHOD_GET; // "GET"
Message\METHOD_POST; // "POST"
Message\METHOD_PUT; // "PUT"
Message\METHOD_DELETE; // "DELETE"
Message\METHOD_PATCH; // "PATCH"

Message\STATUS_OK; // 200
Message\STATUS_CREATED; // 201
Message\STATUS_NO_CONTENT; // 204
Message\STATUS_NOT_FOUND; // 404
Message\STATUS_INTERNAL_SERVER_ERROR; // 500

Message\reason_phrase(Message\STATUS_OK); // "OK"
Message\reason_phrase(Message\STATUS_NOT_FOUND); // "Not Found"
Message\reason_phrase(Message\STATUS_TOO_MANY_REQUESTS); // "Too Many Requests"
Message\reason_phrase(999); // "status code 999"
```

## Protocol Versions

| Version | Enum Case | Value |
|---------|-----------|-------|
| HTTP/1.0 | `ProtocolVersion::V10` | `HTTP/1.0` |
| HTTP/1.1 | `ProtocolVersion::V11` | `HTTP/1.1` |
| HTTP/2 | `ProtocolVersion::V20` | `HTTP/2` |
| HTTP/3 | `ProtocolVersion::V30` | `HTTP/3` |

## Status Code Classes

| Range | Class | Example |
|-------|-------|---------|
| 1xx | Informational | 100 Continue, 101 Switching Protocols |
| 2xx | Successful | 200 OK, 201 Created, 204 No Content |
| 3xx | Redirection | 301 Moved Permanently, 304 Not Modified |
| 4xx | Client Error | 400 Bad Request, 404 Not Found, 429 Too Many Requests |
| 5xx | Server Error | 500 Internal Server Error, 502 Bad Gateway |

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 9110 | HTTP semantics: methods, status codes, header fields, message body |
| RFC 9112 | HTTP/1.1 message syntax (request line, status line, headers) |
| RFC 9113 | HTTP/2 pseudo-headers, trailers, binary framing semantics |
| RFC 9114 | HTTP/3 message mapping over QUIC |

See [src/Psl/HTTP/Message/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/http-message/src/Psl/HTTP/Message/) for the full API.
