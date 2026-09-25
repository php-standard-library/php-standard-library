# Network

The `Network` component defines the shared interfaces and types used by all networking components (`TCP`, `Unix`, `TLS`, `UDP`).

It provides the abstraction layer that protocol-specific components build on top of, ensuring a consistent API across transport types.

## Core Concepts

### Address

`Address` is an immutable value object representing a network address. It carries a scheme (TCP, UDP, or Unix), a host, and an optional port.

```php
use Psl\Network\Address;

$tcp = Address::tcp('127.0.0.1', 8080);
$udp = Address::udp('0.0.0.0', 9999);
$unix = Address::unix('/tmp/my-app.sock');

echo $tcp->toString(); // "tcp://127.0.0.1:8080"
```

### StreamInterface

`StreamInterface` is the core type for connected network streams (TCP, Unix, TLS). It is a bidirectional stream that supports reading, writing, peeking, and shutting down the write side of a connection.

```php
use Psl\Async;
use Psl\TCP;

// Demonstrate StreamInterface usage with a TCP server and client
$listener = TCP\listen('127.0.0.1');

Async\concurrently([
    'server' => static function () use ($listener): void {
        $stream = $listener->accept();

        // Read and write data
        $stream->writeAll('hello');
        $stream->shutdown(); // signal EOF to the remote peer

        $_ = $stream->readAll();

        // Peek at incoming data without consuming it
        // (only works before data is consumed)

        // Inspect addresses
        $_ = $stream->getLocalAddress();
        $_ = $stream->getPeerAddress();

        $stream->close();
        $listener->close();
    },
    'client' => static function () use ($listener): void {
        $address = $listener->getLocalAddress();
        $client = TCP\connect($address->host, $address->port ?? 0);

        $data = $client->readAll();
        $client->writeAll('reply');
        $client->close();
    },
]);
```

### ListenerInterface

`ListenerInterface` accepts incoming connections and returns `StreamInterface` instances. Each protocol component (TCP, Unix) provides its own listener that extends this interface.

```php
$connection = $listener->accept(); // blocks until a connection arrives
```

### Socket Pairs

`socket_pair()` creates two connected bidirectional streams. Data written to one end can be read from the other, which is useful for inter-process communication and testing.

```php
use Psl\Network;

[$a, $b] = Network\socket_pair();
$a->writeAll('ping');
$a->shutdown();
echo $b->readAll(); // "ping"
```

## Interface Hierarchy

```
IO\CloseHandleInterface
 \-- SocketInterface
      |-- ListenerInterface
      |    |-- TCP\ListenerInterface
      |    \-- Unix\ListenerInterface
      \-- StreamInterface  (+ IO\ReadHandleInterface, IO\WriteHandleInterface)
           |-- TCP\StreamInterface
           |-- Unix\StreamInterface
           \-- TLS\StreamInterface
```

See [src/Psl/Network/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/network/src/Psl/Network/) for the full API.
