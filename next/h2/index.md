# H2

The `H2` component implements the HTTP/2 binary framing protocol (RFC 9113) on top of PSL's IO handles. It is a building block for higher-level components like HTTP clients and servers, not an HTTP implementation itself. The protocol is implemented in full - if the RFC defines it, H2 supports it.

## Architecture

If `readEvent()` throws `H2\Exception\StreamException`, H2 has sent RST_STREAM for that stream. The connection remains usable: catch the error and keep reading so other streams can finish. Connection errors still close the connection with GOAWAY (RFC 9113 Section 5.4).

```php
use Psl\H2;

/** @var H2\ConnectionInterface $connection */

while ($connection->isConnected()) {
    try {
        $events = $connection->readEvent();
        // Handle events for the active streams.
    } catch (H2\Exception\StreamException $error) {
        // HEADERS after the peer ended its side report ErrorCode::StreamClosed here.
        // H2 sent RST_STREAM for $error->streamId. Keep reading other streams.
        continue;
    }
}
```

H2 connections take a `Psl\IO\ReadHandleInterface & Psl\IO\WriteHandleInterface` instance. That could be a TCP stream, a TLS connection, a Unix socket, or anything else that implements those interfaces. H2 does not care about the transport.

PSL's IO and Async components are built on top of [Revolt](https://github.com/revoltphp/event-loop). H2 is concurrent by design: operations like `waitForSendWindow()` suspend the current fiber and let the event loop run until a WINDOW_UPDATE arrives. Multiple streams on the same connection run in parallel through fibers.

To use H2 with other event loop libraries like ReactPHP, use [revoltphp/event-loop-adapter-react](https://github.com/revoltphp/event-loop-adapter-react) and wrap your stream in a class implementing `ReadHandleInterface & WriteHandleInterface`.

## Client and Server

Connections are split into `ClientConnection` and `ServerConnection`, each implementing their role-specific interface. Both share common operations (sending headers, data, ping, goaway) via `ConnectionInterface`.

`sendHeaders()`, `sendHeadersWithStatus()`, and `sendPushPromise()` reject connection-specific fields (`connection`, `keep-alive`, `proxy-connection`, `transfer-encoding`, and `upgrade`) and `te` values other than `trailers` (case-insensitive) with `H2\Exception\StreamException`. They also reject values with CR, LF, NUL, or leading or trailing spaces or tabs, and names that are empty or contain bytes outside the token set, such as spaces, control characters, or non-ASCII. They check before HPACK encoding, so callers can correct the fields and retry.

```php
use Psl\H2;
use Psl\H2\Event;
use Psl\HPACK\Header;
use Psl\Network;

// Create a socket pair for client-server communication
[$clientSocket, $serverSocket] = Network\socket_pair();

$client = new H2\ClientConnection($clientSocket);
$server = new H2\ServerConnection($serverSocket);

// Initialize both sides (sends connection preface + SETTINGS)
$client->initialize();
$server->initialize();

// Complete the handshake
$server->readClientPreface();
$server->readEvent(); // client SETTINGS
$client->readEvent(); // server SETTINGS
$client->readEvent(); // SETTINGS ACK
$server->readEvent(); // SETTINGS ACK

// Client sends a request
$streamId = $client->nextStreamId();
$client->sendHeaders(
    $streamId,
    [
        new Header(':method', 'GET'),
        new Header(':path', '/hello'),
        new Header(':scheme', 'https'),
        new Header(':authority', 'example.com'),
        // The te value is case-insensitive; other connection-specific fields cause StreamException.
        // Values with CR, LF, NUL, or leading or trailing spaces or tabs also fail before sending.
        new Header('te', 'Trailers'),
    ],
    endStream: true,
);

// Server reads the request
$events = $server->readEvent();
foreach ($events as $event) {
    if (!$event instanceof Event\HeadersReceived) {
        continue;
    }

    // Server sends a response
    $server->sendHeadersWithStatus($event->streamId, '200', [
        new Header('content-type', 'text/plain'),
    ]);

    $server->sendData($event->streamId, 'Hello, World!', endStream: true);
}

// A lower receive frame-size limit takes effect when the peer acknowledges it.
$client->sendSettings([H2\Setting::MaxFrameSize->value => 16_384]);

// The decoder also waits for ACK before enforcing a lower table-size limit.
$client->sendSettings([H2\Setting::HeaderTableSize->value => 1024]);
```

After a graceful `goAway()`, keep reading while accepted streams finish.
H2 decodes and discards racing headers and push promises above the last
accepted peer stream so the shared HPACK table stays in sync. It discards
DATA and stream control frames on those streams too, while returning
connection flow-control credit for the full DATA payload, including padding.

## Flow Control

`sendData()` splits data at the peer's frame-size limit and requires the caller to provide enough flow-control window for the whole payload. For large payloads, use `sendAllData()` which automatically splits data into window-sized chunks and waits for WINDOW_UPDATE frames.

`waitForSendWindow()` and `sendAllData()` throw `H2\Exception\StreamException` if the stream closes, resets, or finishes sending while they wait. GOAWAY, transport EOF, or a read failure fails all waiters with `H2\Exception\ConnectionException`. Cancelling a read leaves the connection usable.

A WINDOW_UPDATE that would push a stream's send window above 2^31-1 is a stream error (RFC 9113 Section 6.9.1). `readEvent()` sends `RST_STREAM(FLOW_CONTROL_ERROR)` and throws `H2\Exception\StreamException` with that stream ID, so the caller can fail the affected request. The connection stays usable.

```php
use Psl\H2;
use Psl\HPACK\Header;
use Psl\Str;

/** @var H2\ClientConnectionInterface $client */
/** @var H2\ServerConnectionInterface $server */

$streamId = $client->nextStreamId();
$client->sendHeaders($streamId, [
    new Header(':method', 'POST'),
    new Header(':path', '/upload'),
    new Header(':scheme', 'https'),
    new Header(':authority', 'example.com'),
]);

// sendData splits frames but requires enough window for the whole payload.
// sendAllData also waits for flow-control window updates.
// It throws H2\Exception\StreamException if the stream closes while waiting.
// GOAWAY, EOF, or a read failure instead throws H2\Exception\ConnectionException.
$largePayload = Str\repeat('data', 10_000);
$client->sendAllData($streamId, $largePayload, endStream: true);
```

H2 decodes a complete field block before reporting a stream-state error.
HEADERS received after the peer ends its side cause `STREAM_CLOSED` for
that stream. HEADERS on a locally reserved push stream cause a connection
`PROTOCOL_ERROR`.

`goAway()` accepts a `lastStreamId`. A graceful shutdown can first announce `MAX_STREAM_ID` (2^31-1) and later send the real last stream (RFC 9113 Section 6.8). Peer streams at or below the announced value are still accepted and processed; streams above it are decoded for HPACK state and discarded. A second `goAway()` never raises the announced value.

## Server Push

Server push is part of the HTTP/2 specification, so H2 implements it. Whether higher-level code chooses to use it is an application-level decision. `sendPushPromise()` sends pushes, `rejectPush()` declines them.

Clients reject promised requests unless they use GET or HEAD, include a non-empty `:authority`, and declare no content. H2 checks the associated stream and decodes the full field block before rejecting a promised request. Since H2 accepts any IO handle, callers must verify that the server is authoritative for the promised origin before using its response; use `resetStream($promisedStreamId, H2\ErrorCode::ProtocolError)` if it is not.

```php
use Psl\H2;
use Psl\HPACK\Header;

/** @var H2\ServerConnectionInterface $server */
/** @var H2\ClientConnectionInterface $client */

// Server pushes a CSS file alongside the HTML response
$requestStreamId = 1; // client-initiated stream
$pushStreamId = $server->nextStreamId();

$server->sendPushPromise($requestStreamId, $pushStreamId, [
    new Header(':method', 'GET'),
    new Header(':path', '/style.css'),
    new Header(':scheme', 'https'),
    new Header(':authority', 'example.com'),
]);

// Send the pushed response
$server->sendHeadersWithStatus($pushStreamId, '200', [
    new Header('content-type', 'text/css'),
]);
$server->sendData($pushStreamId, 'body { color: red; }', endStream: true);

// Check the promised origin against the server's authority before using a push.
// Reset an unauthorized promised stream with H2\ErrorCode::ProtocolError.

// Client can reject unwanted pushes
$client->rejectPush($pushStreamId);
```

## Extended CONNECT

RFC 8441 enables protocols like WebSocket to run over HTTP/2 streams. The client sends an extended CONNECT request with a `:protocol` pseudo-header, and after the server responds with 200, the stream becomes a bidirectional byte tunnel.

```php
use Psl\H2;
use Psl\HPACK\Header;

/** @var H2\ClientConnectionInterface $client */
/** @var H2\ServerConnectionInterface $server */

$streamId = $client->nextStreamId();
$client->sendExtendedConnect(
    $streamId,
    protocol: 'websocket',
    scheme: 'https',
    authority: 'example.com',
    path: '/chat',
    extraHeaders: [
        new Header('origin', 'https://example.com'),
        new Header('sec-websocket-version', '13'),
    ],
);

// After the server responds with 200, the stream becomes a bidirectional
// byte tunnel. Use sendData/readEvent to exchange protocol-specific frames.
```

## Alt-Svc and Origin

Servers can advertise alternative service endpoints (RFC 7838) for protocol migration, and declare authoritative origins (RFC 8336) for connection coalescing.

```php
use Psl\H2;

/** @var H2\ServerConnectionInterface $server */

// Alt-Svc (RFC 7838) advertises alternative service endpoints,
// enabling HTTP/2 to HTTP/3 migration.
$server->sendAltSvc(0, 'https://example.com', 'h3=":443"; ma=2592000');

// ORIGIN (RFC 8336) declares which origins the server is authoritative for,
// enabling connection coalescing across multiple domains.
$server->sendOrigin([
    'https://example.com',
    'https://cdn.example.com',
    'https://api.example.com',
]);
```

## Configuration

H2 allows 100 resets caused by peer errors per 10-second window by default.
The next error ends the connection with `ENHANCE_YOUR_CALM`. This counts
malformed streams, refused streams and stream-window overflows. It does not
count calls to `resetStream()` made by the application.

Set `RateLimiter::STREAM_ERROR_RESET` in `Configuration::rateLimiter` to
change this limit. Passing a limiter without that key disables it. The
incoming `RST_STREAM` limit remains a separate counter.

```php

// Allow up to 50 resets caused by peer errors in each 10-second window.
$configuration = new H2\Configuration(rateLimiter: new H2\RateLimiter([
    H2\RateLimiter::STREAM_ERROR_RESET => [50, Duration::seconds(10)],
]));
```

A field block arrives as a HEADERS or PUSH_PROMISE frame followed by CONTINUATION frames, and can only be decoded once END_HEADERS arrives. `Configuration::$maxHeaderBlockSize` bounds the fragments buffered for one block, 64 KiB by default (`DEFAULT_MAX_HEADER_BLOCK_SIZE`): a peer that never ends a block, or sends a larger one, gets a `PROTOCOL_ERROR` connection error before the fragment that would exceed the limit is buffered; a block that arrives whole in one frame is not buffered, and `SETTINGS_MAX_FRAME_SIZE` bounds it instead. `0` lifts the limit. The decoded field section is bounded separately. A `SETTINGS_MAX_HEADER_LIST_SIZE` given in `Configuration::$settings` is advertised and enforced; without one, the decoder enforces 64 KiB (`DEFAULT_MAX_RECEIVED_HEADER_LIST_SIZE`) without advertising it, as RFC 9113 Section 6.5.2 permits, since one byte of a block can stand for a 4 KiB table entry. A block that decodes to more fails with `COMPRESSION_ERROR`. Pass `DEFAULT_MAX_HEADER_LIST_SIZE` as the setting to lift that limit. `MAX_CONCURRENT_STREAMS` keeps the RFC's unlimited initial value; a server should set it.

The unified `Configuration` class replaces the deprecated `ServerConfiguration` and `ClientConfiguration`. It provides immutable builder patterns with `with*` methods and supports BDP auto-tuning for both client and server connections. When `maxReceiveWindowSize` is set, the connection dynamically sizes its receive window based on measured throughput and RTT using PING round-trips. Both `ClientConnection` and `ServerConnection` accept the new `Configuration` alongside their legacy types for backwards compatibility.

`sendSettings()` tracks frame-size changes in send order. A lower `MAX_FRAME_SIZE` takes effect after the peer acknowledges that SETTINGS frame. H2 accepts advertised increases while their ACK is pending.

Local `MAX_CONCURRENT_STREAMS` and `INITIAL_WINDOW_SIZE` changes also take effect when their SETTINGS frame is acknowledged. The peer-stream limit and the initial receive window of new streams switch on that ACK, and the receive windows of open streams move by the difference, as RFC 9113 Section 6.9.2 requires.

The same ACK ordering applies to the decoder's `HEADER_TABLE_SIZE` limit. A reduction waits for its ACK; H2 accepts an advertised increase while its ACK is pending. The outbound encoder starts at no more than the peer's default 4096-byte table, regardless of the larger receive limit configured locally. It grows only when the peer advertises a larger limit.

## Extension Frames

Beyond the core 10 frame types, the component supports:

- **ALTSVC** (0xa) -- RFC 7838 alternative service advertisement
- **ORIGIN** (0xc) -- RFC 8336 authoritative origin declaration
- **PRIORITY_UPDATE** (0x10) -- RFC 9218 extensible priority scheme

Unknown frame types are silently ignored per the RFC, ensuring forward compatibility.

Invalid payload sizes use `FRAME_SIZE_ERROR` (RFC 9113 Sections 4.2 and 6). H2 resets an active stream for a malformed PRIORITY frame; other size errors close the connection. Excess padding still uses `PROTOCOL_ERROR`.

```php
use Psl\H2;
use Psl\H2\Frame;

$invalidPing = new Frame\RawFrame(Frame\FrameType::Ping->value, 0, 0, 'short');
try {
    Frame\PingFrame::fromRaw($invalidPing);
} catch (H2\Exception\ProtocolException $error) {
    // fromRaw() reports the same code that a connection sends in GOAWAY.
    assert($error->errorCode === H2\ErrorCode::FrameSizeError, 'Invalid payload sizes use FRAME_SIZE_ERROR.');
}
```

See [src/Psl/H2/](https://github.com/php-standard-library/php-standard-library/tree/next/packages/h2/src/Psl/H2/) for the full API.
