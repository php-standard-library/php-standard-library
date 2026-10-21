# TLS

The `TLS` component provides a transport-agnostic API for TLS encryption. It operates on `Network\StreamInterface`, meaning it can upgrade any connected stream (TCP, Unix, or other) to a TLS-encrypted stream.

It supports TLS 1.0--1.3, ALPN protocol negotiation, SNI-based certificate selection, mutual TLS authentication, session tickets, certificate pinning, and lazy handshake inspection.

## Usage

@example('networking/tls-connect.php')

## Design

### Client Connections

The simplest path is `TLS\connect()`, which opens a TCP connection and performs the handshake in one step. For more control, use `TLS\Connector` to upgrade an existing stream:

@example('networking/tls-upgrade.php')

Configure the client with `ClientConfiguration`:

@example('networking/tls-client-config.php')

### Server Connections

Use `Acceptor` to perform TLS handshakes on incoming streams:

@example('networking/tls-server.php')

### TLS Listener

`TLS\Listener` wraps any `Network\ListenerInterface` and automatically performs TLS handshakes on accepted connections. This is the simplest way to build a TLS server:

@example('networking/tls-listener.php')

Each accepted connection gets a handshake deadline, 10 seconds by default, which the optional third constructor argument (`handshakeTimeout`, a `Duration`) changes. A connection that fails its handshake, exceeds the deadline, or is cancelled is closed; an exceeded deadline surfaces as a `HandshakeFailedException` whose previous exception is the `CancelledException` that ended the handshake. The deadline starts once the inner listener has accepted the connection, independently of the token passed to `accept()`.

The handshake runs inside `accept()`, one connection at a time. A peer that connects and then stays silent therefore holds up every later connection until its deadline passes. Servers that must keep accepting while a handshake is slow should accept plain streams from the inner listener and run an `Acceptor` per connection in its own fiber.

### SNI-Based Virtual Hosting (LazyAcceptor)

`LazyAcceptor` peeks at the TLS ClientHello before completing the handshake. This lets you inspect the client's SNI hostname and choose the appropriate `ServerConfiguration` dynamically:

@example('networking/tls-lazy-acceptor.php')

### TLS Connection Pooling

`TLS\TCPConnector` implements `TCP\ConnectorInterface`, which means it can be used with `TCP\SocketPool` to enable connection pooling for TLS connections. This avoids repeated TLS handshakes when making multiple requests to the same host:

@example('networking/tls-pool.php')

## Examples

### HTTPS Client with ALPN

@example('networking/tls-alpn.php')

### Certificate Pinning

@example('networking/tls-pinning.php')

### STARTTLS (Upgrade Mid-Connection)

@example('networking/tls-starttls.php')

### Inspecting Connection State

@example('networking/tls-inspect-state.php')

### Writes and TLS records

OpenSSL encrypts a write into a TLS record before it reaches the socket. When the socket is not writable, PHP reports the write as short although that record is already committed and must be sent, unchanged, before any other bytes. TLS streams handle this for you: a short `tryWrite()` counts up to one record (16 KiB) of the unwritten remainder as accepted and sends it before the bytes of any later write, so a `tryWrite()` followed by a write of different bytes, or a cancelled `writeAll()` followed by another write, never corrupts the stream. Consequences:

- `tryWrite()` returns `0` while that record cannot be sent yet, and while a `write()` or `writeAll()` is in progress on another fiber;
- `write()` and `writeAll()` return only once every byte they count has been handed to the socket;
- `shutdown()` throws an `IO\Exception\RuntimeException` while a record accepted by `tryWrite()` is still pending; call `write()` or `writeAll()` first.

### Cancellation

All TLS operations that suspend (handshakes) accept a `CancellationTokenInterface`. This allows you to cancel slow or hanging handshakes:

- `Acceptor::accept($stream, $cancellation)` -- cancel server-side handshake
- `LazyAcceptor::accept($stream, $cancellation)` -- cancel ClientHello peek
- `ClientHello::complete($config, $cancellation)` -- cancel deferred handshake
- `Connector::connect($stream, $host, $cancellation)` -- cancel client-side handshake
- `TLS\connect($host, $port, $config, $cancellation)` -- cancellation propagates through both TCP connect and TLS handshake

@example('networking/tls-cancellation.php')

See `src/Psl/TLS/` for the full API.
