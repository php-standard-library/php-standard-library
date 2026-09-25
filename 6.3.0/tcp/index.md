# TCP

The `TCP` component provides a non-blocking API for TCP client and server connections, built on top of PSL's IO and Network abstractions.

## Usage

```php
use Psl\Async;
use Psl\TCP;

$listener = TCP\listen('127.0.0.1');

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $request = $connection->readAll();
        $connection->writeAll("echo: {$request}");
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($listener): void {
        $address = $listener->getLocalAddress();
        $client = TCP\connect($address->host, $address->port ?? 0);
        $client->writeAll('hello');
        $client->shutdown();
        $response = $client->readAll();
        $client->close();
    },
]);
```

## Design

### ConnectorInterface

All TCP connectors implement `ConnectorInterface`, making them interchangeable and composable. The built-in connectors are:

- **`Connector`** -- the default connector wrapping `TCP\connect()`.
- **`RetryConnector`** -- wraps any connector with exponential backoff retry on failure.
- **`StaticConnector`** -- redirects all connections to a fixed host and port (useful for testing).

### SocketPool

`SocketPool` reuses idle TCP connections. Checked-in connections stay alive for a configurable idle timeout before being closed.

```php
use Psl\Async;
use Psl\TCP;

$listener = TCP\listen('127.0.0.1');
$address = $listener->getLocalAddress();

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        // Keep connection open until client is done
        $connection->readAll();
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($address): void {
        $pool = new TCP\SocketPool();
        $stream = $pool->checkout($address->host, $address->port ?? 0);
        // ... use stream ...
        $pool->checkin($stream);

        // Later -- reuses the same connection
        $stream = $pool->checkout($address->host, $address->port ?? 0);
        $pool->clear($stream);
        $pool->close();
    },
]);
```

### Configuration

`TCP\listen()` and `TCP\connect()` accept configuration objects that control socket behavior:

- **`ListenConfiguration`** -- noDelay, reuseAddress, reusePort, backlog (default 512), idleConnections (default 256), bindTo
- **`ConnectConfiguration`** -- noDelay, bindTo

The `bindTo` option on both configuration objects allows binding to a specific local address before connecting or listening. This is useful for selecting a particular network interface or source IP:

```php
use Psl\Async;
use Psl\TCP;

// Connect from a specific local address.
// The bindTo option binds the socket to a local IP before connecting,
// useful for selecting a particular network interface or source IP.
$listener = TCP\listen('127.0.0.1');
$port = $listener->getLocalAddress()->port ?? 0;

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $connection->writeAll('hello');
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($port): void {
        $stream = TCP\connect('127.0.0.1', $port, new TCP\ConnectConfiguration(bindTo: '127.0.0.1:0'));

        $local = $stream->getLocalAddress();
        Psl\invariant($local->host === '127.0.0.1', 'Host address is wrong');

        $data = $stream->readAll();
        $stream->close();
    },
]);
```

All configuration objects are immutable and provide `with*` builder methods for fluent configuration:

```php
use Psl\IO;
use Psl\TCP;

// Configuration objects are immutable with fluent with* builder methods
$config = TCP\ListenConfiguration::default()->withNoDelay(true)->withBacklog(2048)->withIdleConnections(128);

$listener = TCP\listen('127.0.0.1', 0, $config);

$address = $listener->getLocalAddress();
IO\write_line('Listening on %s:%d', $address->host, $address->port ?? 0);

$listener->close();

// ConnectConfiguration works the same way
$connectConfig = TCP\ConnectConfiguration::default()->withNoDelay(true);

IO\write_line('Connect config noDelay: %s', $connectConfig->noDelay ? 'true' : 'false');
```

```php
use Psl\TCP;

// Default backlog of 512
$listener = TCP\listen('127.0.0.1', 8080);

// High-throughput server with larger backlog
$listener = TCP\listen('127.0.0.1', 8080, new TCP\ListenConfiguration(backlog: 4096));
```

### Low-Level Socket (Deprecated)

> **Deprecated**: `TCP\Socket` is deprecated in favor of the `bindTo` option on `ConnectConfiguration` and `ListenConfiguration`. It will be removed in PSL 7.0.

`Socket` previously provided fine-grained control over socket creation via a bind-then-connect/listen pattern. This is now replaced by the `bindTo` configuration option. See the example below for the migration pattern:

```php
use Psl\Async;
use Psl\TCP;

// DEPRECATED: TCP\Socket is deprecated. Use the bindTo option instead.
//
// Before (deprecated):
//   $socket = TCP\Socket::createV4();
//   $socket->bind('127.0.0.1', 0);
//   $stream = $socket->connect('example.com', 443);
//
// After:
//   $stream = TCP\connect('example.com', 443, new TCP\ConnectConfiguration(
//       bindTo: '127.0.0.1:0',
//   ));

// The new way: use bindTo on ConnectConfiguration or ListenConfiguration.
$listener = TCP\listen('127.0.0.1', 0, new TCP\ListenConfiguration(reuseAddress: true, noDelay: true));

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $data = $connection->readAll();
        $connection->writeAll($data);
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($listener): void {
        $address = $listener->getLocalAddress();
        $client = TCP\connect($address->host, $address->port ?? 0, new TCP\ConnectConfiguration(bindTo: '127.0.0.1:0'));
        $client->writeAll('test');
        $client->shutdown();
        $response = $client->readAll();
        $client->close();
    },
]);
```

## Examples

### Echo Server

```php
use Psl\Async;
use Psl\TCP;

$listener = TCP\listen('127.0.0.1');

Async\concurrently([
    'server' => static function () use ($listener): void {
        echo "Listening on {$listener->getLocalAddress()->toString()}\n";

        // Accept one connection then shut down
        $connection = $listener->accept();
        Async\run(static function () use ($connection): void {
            $data = $connection->readAll();
            $connection->writeAll($data);
            $connection->close();
        })->await();

        $listener->close();
    },
    'client' => static function () use ($listener): void {
        $address = $listener->getLocalAddress();
        $client = TCP\connect($address->host, $address->port ?? 0);
        $client->writeAll('hello from client');
        $client->shutdown();
        $response = $client->readAll();
        echo "Got: {$response}\n";
        $client->close();
    },
]);
```

### Client with Cancellation

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;

$listener = TCP\listen('127.0.0.1');

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $_ = $connection->readAll();
        $connection->writeAll("HTTP/1.0 200 OK\r\n\r\nHello");
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($listener): void {
        $address = $listener->getLocalAddress();
        $client = TCP\connect(
            $address->host,
            $address->port ?? 0,
            cancellation: new Async\TimeoutCancellationToken(Duration::seconds(5)),
        );
        $client->writeAll("GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");
        $client->shutdown();
        $_ = $client->readAll();
        $client->close();
    },
]);
```

### Retry with Backoff

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\TCP;

// Create a retry connector with exponential backoff
$connector = new TCP\RetryConnector(
    new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true)),
    maxAttempts: 5,
    backoff: Duration::milliseconds(500),
);

// Demonstrate by connecting to a local server
$listener = TCP\listen('127.0.0.1');

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $connection->writeAll('connected');
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($connector, $listener): void {
        $address = $listener->getLocalAddress();
        $stream = $connector->connect($address->host, $address->port ?? 0);
        $data = $stream->readAll();
        $stream->close();
    },
]);
```

### Cancellable Accept

`ListenerInterface::accept()` accepts a `CancellationTokenInterface`, allowing you to cancel waiting for connections, for example during a graceful shutdown:

```php
use Psl\Async;
use Psl\IO;
use Psl\TCP;

$listener = TCP\listen('127.0.0.1', 0);
$token = new Async\SignalCancellationToken();

// Simulate shutdown after 50ms
Async\Scheduler::delay(Psl\DateTime\Duration::milliseconds(50), static fn(string $_) => $token->cancel());

while (true) {
    try {
        $conn = $listener->accept($token);
    } catch (Async\Exception\CancelledException) {
        IO\write_line('Graceful shutdown');
        break;
    }
}

$listener->close();
```

### Restricted Listener

`RestrictedListener` wraps any `ListenerInterface` and restricts connections to a set of allowed `IP\Address` and `CIDR\Block` entries. Rejected connections are closed silently.

```php
use Psl\Async;
use Psl\CIDR;
use Psl\IO;
use Psl\IP;
use Psl\TCP;

// Only allow connections from localhost and the 10.0.0.0/8 private range
$inner = TCP\listen('127.0.0.1', 0);
$listener = new TCP\RestrictedListener($inner, [
    IP\Address::parse('127.0.0.1'),
    new CIDR\Block('10.0.0.0/8'),
]);

$address = $listener->getLocalAddress();
IO\write_line('Restricted listener on %s:%d', $address->host, $address->port ?? 0);

$token = new Async\SignalCancellationToken();
Async\Scheduler::delay(Psl\DateTime\Duration::milliseconds(50), static fn() => (
    // Simulate shutdown after 50ms
    $token->cancel()
));

while (true) {
    try {
        $stream = $listener->accept($token);

        IO\write_line('Accepted from %s', $stream->getPeerAddress()->host);

        $stream->close();
    } catch (Async\Exception\CancelledException) {
        break;
    }
}

$listener->close();
```

### Composite Listener

`Network\CompositeListener` accepts connections from multiple listeners concurrently through a single `accept()` call. Each inner listener runs its own accept loop in a separate fiber, and connections are funneled through a shared channel. Closing the composite listener closes all inner listeners.

```php
use Psl\Async;
use Psl\IO;
use Psl\Network;
use Psl\TCP;

// Listen on two different ports simultaneously
$listener1 = TCP\listen('127.0.0.1', 0);
$listener2 = TCP\listen('127.0.0.1', 0);

$composite = new Network\CompositeListener([$listener1, $listener2]);

$addr1 = $listener1->getLocalAddress();
$addr2 = $listener2->getLocalAddress();
IO\write_line('Listening on %s:%d and %s:%d', $addr1->host, $addr1->port ?? 0, $addr2->host, $addr2->port ?? 0);

// Simulate shutdown after 50ms
$token = new Async\SignalCancellationToken();
Async\Scheduler::delay(Psl\DateTime\Duration::milliseconds(50), static fn(string $_) => $token->cancel());

while (true) {
    try {
        $stream = $composite->accept($token);

        IO\write_line('Connection from %s', $stream->getPeerAddress()->host);

        $stream->close();
    } catch (Async\Exception\CancelledException) {
        break;
    }
}

// Closes all inner listeners
$composite->close();
```

See [src/Psl/TCP/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/tcp/src/Psl/TCP/) for the full API.
