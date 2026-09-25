# Socks

The `Socks` component provides a SOCKS5 proxy connector that tunnels TCP connections through a SOCKS5 proxy server (RFC 1928).

It implements `TCP\ConnectorInterface`, so it can be used anywhere a TCP connector is expected -- enabling transparent proxy tunneling for any code that accepts a connector.

## Usage

```php
use Psl\Socks;
use Psl\TCP;

// Note: This example requires a running SOCKS5 proxy server.
$connector = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('proxy.example.com', 1080));
$stream = $connector->connect('target.example.com', 80);

$stream->writeAll("GET / HTTP/1.0\r\nHost: target.example.com\r\n\r\n");
$stream->shutdown();
$response = $stream->readAll();
$stream->close();
```

With username/password authentication (RFC 1929):

```php
use Psl\Socks;
use Psl\TCP;

// Note: This example requires a running SOCKS5 proxy server with authentication.
$connector = new Socks\Connector(
    new TCP\Connector(),
    new Socks\Configuration('proxy.example.com', 1080, 'user', 'pass'),
);
```

## Examples

### Tunneling TLS Through a Proxy

```php
use Psl\Socks;
use Psl\TCP;
use Psl\TLS;

// Note: This example requires a running SOCKS5 proxy server.
$proxy = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('proxy.example.com', 1080, 'user', 'pass'));
$stream = $proxy->connect('api.example.com', 443);
$tls = TLS\Connector::default()->connect($stream, 'api.example.com');

$tls->writeAll("GET /data HTTP/1.1\r\nHost: api.example.com\r\n\r\n");
```

### With Connection Pooling

```php
use Psl\Socks;
use Psl\TCP;

// Note: This example requires a running SOCKS5 proxy server.
$proxy = new Socks\Connector(new TCP\Connector(), new Socks\Configuration('proxy.example.com', 1080));
$pool = new TCP\SocketPool(connector: $proxy);

$stream = $pool->checkout('api.example.com', 80);
// ... use stream ...
$pool->checkin($stream);
```

### With Retry

```php
use Psl\DateTime\Duration;
use Psl\Socks;
use Psl\TCP;

// Note: This example requires a running SOCKS5 proxy server.
$proxy = new Socks\Connector(
    new TCP\RetryConnector(new TCP\Connector(), maxAttempts: 3, backoff: Duration::seconds(1)),
    new Socks\Configuration('proxy.example.com', 1080),
);

$stream = $proxy->connect('target.example.com', 80);
```

See [src/Psl/Socks/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/socks/src/Psl/Socks/) for the full API.
