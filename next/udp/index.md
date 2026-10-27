# UDP

The `UDP` component provides a non-blocking API for sending and receiving datagrams over UDP.

It uses two distinct types for type-safe socket usage:

- **`Socket`** -- an unconnected socket for sending/receiving to arbitrary addresses.
- **`ConnectedSocket`** -- a connected socket for communicating with a single peer.

## Usage

```php
use Psl\Async;
use Psl\Network\Address;
use Psl\UDP;

$server = UDP\Socket::bind('127.0.0.1', 0);
$serverAddress = $server->getLocalAddress();

Async\concurrently([
    'server' => static function () use ($server): void {
        [$data, $sender] = $server->receiveFrom(1024);
        $server->sendTo("echo: {$data}", $sender);
        $server->close();
    },
    'client' => static function () use ($serverAddress): void {
        $client = UDP\Socket::bind('127.0.0.1', 0);
        $client->sendTo('hello', Address::udp($serverAddress->host, $serverAddress->port ?? 0));
        [$response, $_] = $client->receiveFrom(1024);
        $client->close();
    },
]);
```

## Design

### Unconnected vs. Connected Sockets

An unconnected `Socket` can send to and receive from any address using `sendTo()` and `receiveFrom()`. When you call `connect()` on it, a `ConnectedSocket` is returned and the `Socket` object is closed. The connected socket uses simpler `send()` and `receive()` methods since the peer is fixed.

With ext-sockets, `connect()` connects the same OS socket in place, so the connected socket keeps the socket's options, its local address and port, and the datagrams already queued on it. It connects to the address a new socket would: an IP literal, or the first address of a host name, in the system's order, that the socket's family reaches (an IPv4 socket skips IPv6 addresses). An IPv6 socket bound to `::` reaches an IPv4 address through its IPv4-mapped form, such as `::ffff:127.0.0.1`, which its local address then shows. Without the extension, a new socket bound to the same local address takes its place: it starts without those options and queued datagrams, and another process may take the port in between. The same replacement, by an IPv4 socket on a new port, runs for an IPv4 address on an IPv6 socket that is IPv6-only or bound to any address but `::`, and a connect that fails in place is replaced too, so it fails, or succeeds, as it does without the extension. An IPv4 socket cannot connect to an IPv6 address.

```php
use Psl\Async;
use Psl\UDP;

$server = UDP\Socket::bind('127.0.0.1', 0);
$serverAddress = $server->getLocalAddress();

Async\concurrently([
    'server' => static function () use ($server): void {
        [$data, $sender] = $server->receiveFrom(1024);
        $server->sendTo("echo: {$data}", $sender);
        $server->close();
    },
    'client' => static function () use ($serverAddress): void {
        // Start with an unconnected socket
        $socket = UDP\Socket::bind('127.0.0.1', 0);

        // Connect returns a ConnectedSocket -- the original socket is closed
        $connected = $socket->connect($serverAddress->host, $serverAddress->port ?? 0);

        // $socket is now closed -- only $connected is usable
        $connected->send('hello');
        $_ = $connected->receive(512);
        $connected->close();
    },
]);
```

You can also create a connected socket directly. `UDP\connect()` selects the local socket family from the target, so IPv6 targets work without an explicit local bind:

```php
use Psl\Async;
use Psl\UDP;

$server = UDP\Socket::bind('127.0.0.1', 0);
$serverAddress = $server->getLocalAddress();

Async\concurrently([
    'server' => static function () use ($server): void {
        [$data, $sender] = $server->receiveFrom(1024);
        $server->sendTo("echo: {$data}", $sender);
        $server->close();
    },
    'client' => static function () use ($serverAddress): void {
        // Create a connected socket directly
        $socket = UDP\connect($serverAddress->host, $serverAddress->port ?? 0);
        $socket->send('hello');
        $_ = $socket->receive(512);
        $socket->close();
    },
]);
```

### Receive Errors

On POSIX systems with ext-sockets, a `ConnectedSocket` receives through the extension, so a failed `receive()` or `peek()` throws `Network\Exception\RuntimeException` with the errno as its code, such as `ECONNREFUSED` once the peer's port was unreachable. The socket stays usable, and the next receive waits for the next datagram. Without the extension, and on Windows, such a failure cannot be told apart from a missing datagram: a `receive()` consumes it and waits again, so bound it with a cancellation token. A `peek()` leaves the error pending on BSD systems, such as macOS, and the socket stays readable: the peek then throws `Network\Exception\RuntimeException` with 0 as its code, as every peek does until a receive consumes the error.

A receive or peek that finds no datagram after its socket became readable, as when another receive took the datagram first, waits for the next one. Closing a socket fails every call waiting on it with `IO\Exception\AlreadyClosedException`.

### Configuration

`UDP\Socket::bind()` accepts a `BindConfiguration` to control address reuse, port reuse, and broadcast. Configuration objects are immutable with `with*` builder methods.

## Examples

### Echo Server

```php
use Psl\Async;
use Psl\Network\Address;
use Psl\UDP;

$socket = UDP\Socket::bind('127.0.0.1', 0);
$serverAddress = $socket->getLocalAddress();
echo "Listening on {$serverAddress->toString()}\n";

Async\concurrently([
    'server' => static function () use ($socket): void {
        // Echo one datagram then shut down
        [$data, $sender] = $socket->receiveFrom(65_507);
        $socket->sendTo($data, $sender);
        $socket->close();
    },
    'client' => static function () use ($serverAddress): void {
        $client = UDP\Socket::bind('127.0.0.1');
        $client->sendTo('ping', Address::udp($serverAddress->host, $serverAddress->port ?? 0));
        [$response, $_] = $client->receiveFrom(1024);
        echo "Got: {$response}\n";
        $client->close();
    },
]);
```

### Peek

```php
use Psl\Async;
use Psl\Network\Address;
use Psl\UDP;

$socket = UDP\Socket::bind('127.0.0.1');
$serverAddress = $socket->getLocalAddress();

Async\concurrently([
    'server' => static function () use ($socket): void {
        // Peek at data without consuming it
        [$data, $_sender] = $socket->peekFrom(1024);
        echo "Peeked: {$data}\n";

        // Same data is still available for receiveFrom()
        [$data, $_sender] = $socket->receiveFrom(1024);
        echo "Received: {$data}\n";

        $socket->close();
    },
    'client' => static function () use ($serverAddress): void {
        $client = UDP\Socket::bind('127.0.0.1');
        $client->sendTo('hello', Address::udp($serverAddress->host, $serverAddress->port ?? 0));
        $client->close();
    },
]);
```

See [src/Psl/UDP/](https://github.com/php-standard-library/php-standard-library/tree/next/packages/udp/src/Psl/UDP/) for the full API.
