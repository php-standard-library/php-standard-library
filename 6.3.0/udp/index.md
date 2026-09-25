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

An unconnected `Socket` can send to and receive from any address using `sendTo()` and `receiveFrom()`. When you call `connect()` on it, the original socket is closed and a `ConnectedSocket` is returned. The connected socket uses simpler `send()` and `receive()` methods since the peer is fixed.

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

You can also create a connected socket directly:

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

See [src/Psl/UDP/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/udp/src/Psl/UDP/) for the full API.
