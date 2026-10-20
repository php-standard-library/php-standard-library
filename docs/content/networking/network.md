# Network

The `Network` component defines the shared interfaces and types used by all networking components (`TCP`, `Unix`, `TLS`, `UDP`).

It provides the abstraction layer that protocol-specific components build on top of, ensuring a consistent API across transport types.

## Core Concepts

### Address

`Address` is an immutable value object representing a network address. It carries a scheme (TCP, UDP, or Unix), a host, and an optional port. IPv6 hosts have no brackets in `host`; `toString()` adds them around the host. Both `::1` and `[::1]` inputs produce the same address.

@example('networking/network-addresses.php')

### StreamInterface

`StreamInterface` is the core type for connected network streams (TCP, Unix, TLS). It is a bidirectional stream that supports reading, writing, peeking, and shutting down the write side of a connection.

@example('networking/network-stream-usage.php')

### ListenerInterface

`ListenerInterface` accepts incoming connections and returns `StreamInterface` instances. Each protocol component (TCP, Unix) provides its own listener that extends this interface.

```php
$connection = $listener->accept(); // blocks until a connection arrives
```

### Socket Pairs

`socket_pair()` creates two connected bidirectional streams. Data written to one end can be read from the other, which is useful for inter-process communication and testing.

@example('networking/network-socket-pair.php')

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

See `src/Psl/Network/` for the full API.
