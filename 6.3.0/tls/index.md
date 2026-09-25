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

$listener = TCP\listen('0.0.0.0', 8443);

while (true) {
    $stream = $listener->accept();
    $tls = $acceptor->accept($stream);
    // ... handle encrypted connection
    $tls->close();
}
```

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

See [src/Psl/TLS/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/tls/src/Psl/TLS/) for the full API.
