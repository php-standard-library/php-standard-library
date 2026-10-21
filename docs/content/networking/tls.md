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

#### Per-connection stream contexts

PHP reads a server's TLS options from the stream context of the accepted stream, and captures the client's certificate into that same context. Streams returned by `stream_socket_accept()` share the listening socket's context, so without isolation the settings and captures of one connection would be visible to every other connection of the same listener.

`TCP\listen()` therefore accepts connections through `ext-sockets` (`socket_accept()` and `socket_export_stream()`) when the extension is available, which gives every accepted connection a private stream context. Installing `ext-sockets` is recommended for TLS servers; it is listed as a suggestion by the `tcp` and `tls` packages.

Without `ext-sockets`, accepted streams share the listener's context. PSL still guarantees that a client which presents no certificate is never reported with another client's identity: the captured peer certificate is reset before each handshake, and read and removed in the same step that completes it. What cannot be undone on a shared context is a trust setting written by an earlier handshake: if a `ServerConfiguration` without a certificate authority is used on a stream whose context already carries `cafile` or `capath` from another configuration, the handshake is refused with a `Network\Exception\RuntimeException` instead of silently trusting the previous authority. Other optional settings (`ciphers`, `alpnProtocols`, `sniCertificates`, a key passphrase) of an earlier configuration also remain in effect for a later configuration that omits them. Use one listener per `ServerConfiguration` in that setup, or install `ext-sockets`.

The captured `peer_certificate` and `peer_certificate_chain` options are removed from the stream context after a server handshake; read the peer certificate from `StreamInterface::getState()` instead of the raw context.

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

Pins are SHA-256 fingerprints of the peer's leaf certificate, in hexadecimal; letter case and `:` separators are ignored. They are checked after the handshake, on top of the chain and name verification configured by the other options. A certificate that matches none of the pins closes the connection and throws `HandshakeFailedException`.

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
