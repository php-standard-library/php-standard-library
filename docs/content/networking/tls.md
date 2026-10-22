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

#### SNI certificates

`ServerConfiguration::withSniCertificate()` and `withSniCertificates()` map host names to certificates. A client that asks for one of those names, or for a name that a `*.example.com` key matches, receives that certificate; every other client receives the main certificate. PHP's SNI support has two limits:

- it cannot load a private key protected by a passphrase, so a configuration with such an SNI certificate fails every handshake with a `HandshakeFailedException` before it starts;
- it negotiates no ALPN protocol for a connection served by an SNI certificate.

Where either matters, use `LazyAcceptor` (below) to read the requested name, and complete the handshake with a `ServerConfiguration` whose main certificate is the one that name needs: main certificates accept a passphrase and negotiate ALPN.

#### Per-connection stream contexts

PHP reads a server's TLS options from the stream context of the accepted stream, and captures the client's certificate into that same context. Streams returned by `stream_socket_accept()` share the listening socket's context, so without isolation the settings and captures of one connection would be visible to every other connection of the same listener.

`TCP\listen()` therefore accepts connections through `ext-sockets` (`socket_accept()` and `socket_export_stream()`) when the extension is available, which gives every accepted connection a private stream context. Installing `ext-sockets` is recommended for TLS servers; it is listed as a suggestion by the `tcp` and `tls` packages.

Without `ext-sockets`, accepted streams share the listener's context. PSL still guarantees that a client which presents no certificate is never reported with another client's identity: the captured peer certificate is reset before each handshake, and read and removed in the same step that completes it. What cannot be undone on a shared context is a trust setting written by an earlier handshake, whether by another `ServerConfiguration` or by a client handshake on a stream of the same listener: if a stream's context already carries `cafile`, `capath`, `verify_peer`, `verify_peer_name`, `peer_fingerprint`, `allow_self_signed` or `verify_depth`, and the configuration used for it does not set that option, the handshake is refused with a `Network\Exception\RuntimeException` instead of silently trusting what the earlier handshake trusted. A stale `allow_self_signed`, for example, would accept any self-signed client certificate and report it as the peer. Other optional settings (`ciphers`, `alpnProtocols`, `sniCertificates`, a key passphrase, disabled TLS 1.3 session tickets) of an earlier configuration also remain in effect for a later configuration that omits them. Use one listener per `ServerConfiguration` in that setup, or install `ext-sockets`.

The captured `peer_certificate` and `peer_certificate_chain` options are removed from the stream context after a server handshake; read the peer certificate from `StreamInterface::getState()` instead of the raw context.

#### Session tickets

`sessionTickets: false`, on either configuration, turns TLS session tickets off. A client then offers no TLS 1.2 session ticket; a TLS 1.3 server may still send tickets after the handshake, which nothing stores or reuses. A server issues no TLS 1.2 ticket, and on PHP 8.6 and later no TLS 1.3 ticket either; PHP 8.4 and 8.5 cannot turn TLS 1.3 tickets off, so a server there still sends OpenSSL's default of two.

### TLS Listener

`TLS\Listener` wraps any `Network\ListenerInterface` and automatically performs TLS handshakes on accepted connections. This is the simplest way to build a TLS server:

@example('networking/tls-listener.php')

Each accepted connection gets a handshake deadline, 10 seconds by default, which the optional third constructor argument (`handshakeTimeout`, a `Duration`) changes. A connection that fails its handshake, exceeds the deadline, or is cancelled is closed; an exceeded deadline surfaces as a `HandshakeFailedException` whose previous exception is the `CancelledException` that ended the handshake. The deadline starts once the inner listener has accepted the connection, independently of the token passed to `accept()`.

The handshake runs inside `accept()`, one connection at a time. A peer that connects and then stays silent therefore holds up every later connection until its deadline passes. Servers that must keep accepting while a handshake is slow should accept plain streams from the inner listener and run an `Acceptor` per connection in its own fiber.

### SNI-Based Virtual Hosting (LazyAcceptor)

`LazyAcceptor` peeks at the TLS ClientHello before completing the handshake. This lets you inspect the client's SNI hostname and choose the appropriate `ServerConfiguration` dynamically:

@example('networking/tls-lazy-acceptor.php')

`LazyAcceptor::accept()` waits until the whole ClientHello has arrived, whether the client sends it in one write or in several, and in one TLS record or several, and it consumes none of it: the handshake reads it afterwards. The bytes already received keep the socket readable, so inspection peeks again after a pause that starts at 10 ms and doubles up to 320 ms while nothing new arrives. A peer that stops in the middle of its ClientHello holds the call until the cancellation token ends it, so pass a token with a deadline. A malformed ClientHello throws a `HandshakeFailedException`. A ClientHello longer than 16 KiB is reported from the extensions that end within its first 16 KiB; `complete()` still runs the whole handshake.

### TLS Connection Pooling

`TLS\TCPConnector` implements `TCP\ConnectorInterface`, which means it can be used with `TCP\SocketPool` to enable connection pooling for TLS connections. This avoids repeated TLS handshakes when making multiple requests to the same host:

@example('networking/tls-pool.php')

## Examples

### HTTPS Client with ALPN

@example('networking/tls-alpn.php')

### Certificate Pinning

@example('networking/tls-pinning.php')

Pins are SHA-256 fingerprints of the peer's leaf certificate, in hexadecimal; letter case and `:` separators are ignored. They are checked after the handshake, on top of the chain and name verification configured by the other options. A certificate that matches none of the pins closes the connection and throws `PeerVerificationException`.

### Verification Failures

A handshake that fails because the peer's certificate was rejected, by chain verification (unknown authority, expired or self-signed certificate), by peer name verification, or by a pin, throws `TLS\Exception\PeerVerificationException`. It extends `HandshakeFailedException`, so existing handlers keep matching, and it implements `Network\Exception\NonRetryableExceptionInterface`: `TCP\RetryConnector` and `HTTP\Client\RetryClient` rethrow it at once instead of repeating a handshake that the same certificate will fail again. Other handshake failures, such as a reset connection or a peer that does not speak TLS, remain plain `HandshakeFailedException`s and are retried as before. Both carry the native error in their message.

### STARTTLS (Upgrade Mid-Connection)

@example('networking/tls-starttls.php')

### Inspecting Connection State

@example('networking/tls-inspect-state.php')

### Writes and TLS records

OpenSSL encrypts a write into a TLS record before it reaches the socket. When the socket is not writable, PHP reports the write as short although that record is already committed and must be sent, unchanged, before any other bytes. TLS streams handle this for you: a short `tryWrite()` counts up to one record (16 KiB) of the unwritten remainder as accepted and sends it before the bytes of any later write, so a `tryWrite()` followed by a write of different bytes, or a cancelled `writeAll()` followed by another write, never corrupts the stream. Consequences:

- `tryWrite()` returns `0` while that record cannot be sent yet, and while a `write()` or `writeAll()` is in progress on another fiber;
- `write()` and `writeAll()` return only once every byte they count has been handed to the socket;
- `shutdown()` throws an `IO\Exception\RuntimeException` while a record accepted by `tryWrite()` is still pending; call `write()` or `writeAll()` first.

### Peeking

`peek()` on a TLS stream returns decrypted bytes, as `read()` would, without consuming them. The socket only holds TLS records, so the stream reads the bytes from the connection and keeps them; the next `read()` or `tryRead()` returns them first, and `reachedEndOfDataSource()` stays false while any are kept. A peek returns an empty string only once the peer has ended the data: records that carry no data, such as the TLS 1.3 session tickets a server sends after its handshake, do not end its wait.

### Cancellation

All TLS operations that suspend (handshakes) accept a `CancellationTokenInterface`. This allows you to cancel slow or hanging handshakes:

- `Acceptor::accept($stream, $cancellation)` -- cancel server-side handshake
- `LazyAcceptor::accept($stream, $cancellation)` -- cancel ClientHello peek
- `ClientHello::complete($config, $cancellation)` -- cancel deferred handshake
- `Connector::connect($stream, $host, $cancellation)` -- cancel client-side handshake
- `TLS\connect($host, $port, $config, $cancellation)` -- cancellation propagates through both TCP connect and TLS handshake

@example('networking/tls-cancellation.php')

See `src/Psl/TLS/` for the full API.
