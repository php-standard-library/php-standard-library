# TLS

The `TLS` component provides a transport-agnostic API for TLS encryption. It operates on `Network\StreamInterface`, meaning it can upgrade any connected stream (TCP, Unix, or other) to a TLS-encrypted stream.

It supports TLS 1.0--1.3, ALPN protocol negotiation, SNI-based certificate selection, mutual TLS authentication, session tickets, certificate pinning, and lazy handshake inspection.

## Usage

```php
use Psl\TLS;

// One-step TLS connection
$tls = TLS\connect('example.com', 443);

$tls->writeAll("GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
$tls->shutdown();
$response = $tls->readAll();
$tls->close();
```

## Design

### Client Connections

The simplest path is `TLS\connect()`, which opens a TCP connection and performs the handshake in one step. For more control, use `TLS\Connector` to upgrade an existing stream:

```php
use Psl\TCP;
use Psl\TLS;

// Two-step: connect TCP first, then upgrade
$stream = TCP\connect('example.com', 443);
$tls = TLS\Connector::default()->connect($stream, 'example.com');
```

Configure the client with `ClientConfiguration`:

```php
use Psl\TLS;

$config = TLS\ClientConfiguration::default()
    ->withAlpnProtocols(['h2', 'http/1.1'])
    ->withMinimumVersion(TLS\Version::Tls12);

$tls = TLS\connect('example.com', 443, $config);
```

### Server Connections

Use `Acceptor` to perform TLS handshakes on incoming streams:

```php
use Psl\TCP;
use Psl\TLS;

// Note: This example requires valid TLS certificate files to run.
// Replace the paths below with actual certificate and key files.
$certFile = '/etc/ssl/certs/server.pem';
$keyFile = '/etc/ssl/private/server.key';

$cert = TLS\Certificate::create($certFile, $keyFile);
$acceptor = new TLS\Acceptor(TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['h2', 'http/1.1']));

// With ext-sockets, every accepted connection owns a private stream context, so the TLS
// options and peer certificate of one connection can never be observed by another.
$listener = TCP\listen('0.0.0.0', 8443);

while (true) {
    $stream = $listener->accept();
    $tls = $acceptor->accept($stream);
    // ... handle encrypted connection
    $tls->close();
}
```

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

```php
use Psl\Async;
use Psl\IO;
use Psl\TCP;
use Psl\TLS;

// Create a TLS listener wrapping a TCP listener
$certificate = new TLS\Certificate('server.pem', 'server.key');
$listener = new TLS\Listener(TCP\listen('127.0.0.1', 0), TLS\ServerConfiguration::create($certificate));

$address = $listener->getLocalAddress();
IO\write_line('TLS server listening on %s:%d', $address->host, $address->port ?? 0);

// Simulate shutdown after 50ms
$token = new Async\SignalCancellationToken();
Async\Scheduler::delay(Psl\DateTime\Duration::milliseconds(50), static fn(string $_) => $token->cancel());

while (true) {
    try {
        $stream = $listener->accept($token);

        IO\write_line('Accepted TLS connection from %s', $stream->getPeerAddress()->host);

        $stream->close();
    } catch (Async\Exception\CancelledException) {
        break;
    }
}

$listener->close();
```

Each accepted connection gets a handshake deadline, 10 seconds by default, which the optional third constructor argument (`handshakeTimeout`, a `Duration`) changes. A connection that fails its handshake or exceeds the deadline is closed; an exceeded deadline surfaces as a `HandshakeFailedException` whose previous exception is the `CancelledException` that ended the handshake. The deadline starts once the inner listener has accepted the connection, independently of the token passed to `accept()`.

Handshakes run concurrently. While an `accept()` call waits, the listener takes connections from the inner listener and runs each handshake in a fiber of its own, up to the optional fourth constructor argument (`maxPendingHandshakes`, 256 by default) at a time; while that many are pending it takes no further connection, so the inner listener's backlog, and then the operating system's, hold the ones that arrive. `accept()` returns the next connection whose handshake completes, in completion order: a peer that connects and then stays silent holds only its own slot until its deadline passes. A handshake that fails or times out closes its connection, and its `HandshakeFailedException` is delivered to the next `accept()` call, as when handshakes ran one at a time; a server that wants connections only catches it and calls `accept()` again. Cancelling an `accept()` call ends that call only: pending handshakes finish or time out on their own, and their outcomes wait for the next call, while no further connection is taken from the inner listener until a call waits again. `close()` cancels every pending handshake, which closes those connections, closes the connections whose handshakes completed for nobody, makes every waiting `accept()` call throw `AlreadyStoppedException`, and closes the inner listener.

### SNI-Based Virtual Hosting (LazyAcceptor)

`LazyAcceptor` peeks at the TLS ClientHello before completing the handshake. This lets you inspect the client's SNI hostname and choose the appropriate `ServerConfiguration` dynamically:

```php
use Psl\TCP;
use Psl\TLS;

// Note: This example requires valid TLS certificate files to run.
// Replace the paths below with actual certificate and key files.
$lazy = TLS\LazyAcceptor::default();
$listener = TCP\listen('0.0.0.0', 8443);

$configs = [
    'api.example.com' => TLS\ServerConfiguration::create(TLS\Certificate::create(
        '/etc/ssl/certs/api.pem',
        '/etc/ssl/private/api.key',
    )),
    'www.example.com' => TLS\ServerConfiguration::create(TLS\Certificate::create(
        '/etc/ssl/certs/www.pem',
        '/etc/ssl/private/www.key',
    )),
];

$default = TLS\ServerConfiguration::create(TLS\Certificate::create(
    '/etc/ssl/certs/default.pem',
    '/etc/ssl/private/default.key',
));

while (true) {
    $stream = $listener->accept();
    $hello = $lazy->accept($stream);
    $server = $hello->getServerName();
    $config = $server === null ? $default : $configs[$server] ?? $default;
    $tls = $hello->complete($config);
    // ... handle connection
}
```

`LazyAcceptor::accept()` waits until the whole ClientHello has arrived, whether the client sends it in one write or in several, and in one TLS record or several, and it consumes none of it: the handshake reads it afterwards. The bytes already received keep the socket readable, so inspection peeks again after a pause that starts at 10 ms and doubles up to 320 ms while nothing new arrives. A peer that stops in the middle of its ClientHello holds the call until the cancellation token ends it, so pass a token with a deadline. A malformed ClientHello throws a `HandshakeFailedException`. A ClientHello longer than 16 KiB is reported from the extensions that end within its first 16 KiB; `complete()` still runs the whole handshake.

### TLS Connection Pooling

`TLS\TCPConnector` implements `TCP\ConnectorInterface`, which means it can be used with `TCP\SocketPool` to enable connection pooling for TLS connections. This avoids repeated TLS handshakes when making multiple requests to the same host:

```php
use Psl\TCP;
use Psl\TLS;

// Create a TLS-aware connector that implements TCP\ConnectorInterface
$connector = new TLS\TCPConnector(
    new TCP\Connector(),
    new TLS\Connector(TLS\ClientConfiguration::default()->withPeerVerification(true)),
);

// Use it with a standard TCP socket pool for connection reuse
$pool = new TCP\SocketPool($connector);

// First request - establishes a new TLS connection
$stream = $pool->checkout('example.com', 443);
$stream->writeAll("GET / HTTP/1.1\r\nHost: example.com\r\nConnection: keep-alive\r\n\r\n");
$response = $stream->read();
$pool->checkin($stream);

// Second request - reuses the existing TLS connection (no new handshake)
$stream = $pool->checkout('example.com', 443);
$stream->writeAll("GET /about HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n");
$response = $stream->read();
$pool->clear($stream);

$pool->close();
```

## Examples

### HTTPS Client with ALPN

```php
use Psl\TLS;

$config = TLS\ClientConfiguration::default()->withAlpnProtocols(['h2', 'http/1.1']);

$tls = TLS\connect('example.com', 443, $config);

$protocol = $tls->getState()->alpnProtocol; // 'h2'
```

### Certificate Pinning

```php
use Psl\TLS;

// Note: Replace the fingerprints below with actual SHA-256 certificate fingerprints.
$config = TLS\ClientConfiguration::default()->withPeerFingerprints([
    'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
    'e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6',
]);

$tls = TLS\connect('api.example.com', 443, $config);
```

Pins are SHA-256 fingerprints of the peer's leaf certificate, in hexadecimal; letter case and `:` separators are ignored. They are checked after the handshake, on top of the chain and name verification configured by the other options. A certificate that matches none of the pins closes the connection and throws `PeerVerificationException`.

### Verification Failures

A handshake that fails because the peer's certificate was rejected, by chain verification (unknown authority, expired or self-signed certificate), by peer name verification, or by a pin, throws `TLS\Exception\PeerVerificationException`. It extends `HandshakeFailedException`, so existing handlers keep matching, and it implements `Network\Exception\NonRetryableExceptionInterface`: `TCP\RetryConnector` and `HTTP\Client\RetryClient` rethrow it at once instead of repeating a handshake that the same certificate will fail again. Other handshake failures, such as a reset connection or a peer that does not speak TLS, remain plain `HandshakeFailedException`s and are retried as before. Both carry the native error in their message.

### STARTTLS (Upgrade Mid-Connection)

```php
use Psl\IO;
use Psl\TCP;
use Psl\TLS;

// Note: This example requires valid TLS certificate files to run.
// Replace the paths below with actual certificate and key files.
$listener = TCP\listen('0.0.0.0', 2525);
$cert = TLS\Certificate::create('/etc/ssl/certs/mail.pem', '/etc/ssl/private/mail.key');
$acceptor = new TLS\Acceptor(TLS\ServerConfiguration::create($cert));

$stream = $listener->accept();

// Plaintext phase
$reader = new IO\Reader($stream);
$line = $reader->readLine(); // "EHLO client.example.com"
$stream->writeAll("250-mail.example.com\r\n250 STARTTLS\r\n");

$line = $reader->readLine(); // "STARTTLS"
$stream->writeAll("220 Ready to start TLS\r\n");

// Upgrade to TLS
$tls = $acceptor->accept($stream);

// ... continue with encrypted SMTP
```

### Inspecting Connection State

```php
use Psl\TLS;

$tls = TLS\connect('example.com', 443);

$state = $tls->getState();
echo "TLS {$state->version->name}\n"; // "TLS Tls13"
echo "Cipher: {$state->cipherName}\n"; // "TLS_AES_256_GCM_SHA384"
echo "Bits: {$state->cipherBits}\n"; // 256
echo "ALPN: {$state->alpnProtocol}\n"; // "h2" or null

if ($state->peerCertificate !== null) {
    echo "Subject: {$state->peerCertificate->subject}\n";
    echo "Issuer: {$state->peerCertificate->issuer}\n";
    echo "Valid until: {$state->peerCertificate->validTo->toRfc3339()}\n";
}

$tls->close();
```

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

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\TLS;

// Cancel if the TLS handshake takes more than 5 seconds
$token = new Async\TimeoutCancellationToken(Duration::seconds(5));

try {
    $stream = TLS\connect('example.com', 443, cancellation: $token);

    IO\write_line('Connected!');

    $stream->close();
} catch (Async\Exception\CancelledException) {
    IO\write_line('TLS handshake timed out');
}
```

See [src/Psl/TLS/](https://github.com/php-standard-library/php-standard-library/tree/6.4.2/packages/tls/src/Psl/TLS/) for the full API.
