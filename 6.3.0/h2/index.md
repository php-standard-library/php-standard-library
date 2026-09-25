# H2

The `H2` component implements the HTTP/2 binary framing protocol (RFC 9113) on top of PSL's IO handles. It is a building block for higher-level components like HTTP clients and servers, not an HTTP implementation itself. The protocol is implemented in full - if the RFC defines it, H2 supports it.

## Architecture

H2 connections take a `Psl\IO\ReadHandleInterface & Psl\IO\WriteHandleInterface` instance. That could be a TCP stream, a TLS connection, a Unix socket, or anything else that implements those interfaces. H2 does not care about the transport.

PSL's IO and Async components are built on top of [Revolt](https://github.com/revoltphp/event-loop). H2 is concurrent by design: operations like `waitForSendWindow()` suspend the current fiber and let the event loop run until a WINDOW_UPDATE arrives. Multiple streams on the same connection run in parallel through fibers.

To use H2 with other event loop libraries like ReactPHP, use [revoltphp/event-loop-adapter-react](https://github.com/revoltphp/event-loop-adapter-react) and wrap your stream in a class implementing `ReadHandleInterface & WriteHandleInterface`.

## Client and Server

Connections are split into `ClientConnection` and `ServerConnection`, each implementing their role-specific interface. Both share common operations (sending headers, data, ping, goaway) via `ConnectionInterface`.

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
```

## Flow Control

`sendData()` sends a single frame and requires the caller to manage flow control windows. For large payloads, use `sendAllData()` which automatically splits data into window-sized chunks and waits for WINDOW_UPDATE frames.

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

// sendAllData handles flow control - no manual window management needed
$largePayload = Str\repeat('data', 10_000);
$client->sendAllData($streamId, $largePayload, endStream: true);
```

## Server Push

Server push is part of the HTTP/2 specification, so H2 implements it. Whether higher-level code chooses to use it is an application-level decision. `sendPushPromise()` sends pushes, `rejectPush()` declines them.

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

The unified `Configuration` class replaces the deprecated `ServerConfiguration` and `ClientConfiguration`. It provides immutable builder patterns with `with*` methods and supports BDP auto-tuning for both client and server connections. When `maxReceiveWindowSize` is set, the connection dynamically sizes its receive window based on measured throughput and RTT using PING round-trips. Both `ClientConnection` and `ServerConnection` accept the new `Configuration` alongside their legacy types for backwards compatibility.

## Extension Frames

Beyond the core 10 frame types, the component supports:

- **ALTSVC** (0xa) -- RFC 7838 alternative service advertisement
- **ORIGIN** (0xc) -- RFC 8336 authoritative origin declaration
- **PRIORITY_UPDATE** (0x10) -- RFC 9218 extensible priority scheme

Unknown frame types are silently ignored per the RFC, ensuring forward compatibility.

See [src/Psl/H2/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/h2/src/Psl/H2/) for the full API.
