# UDP

The `UDP` component provides a non-blocking API for sending and receiving datagrams over UDP.

It uses two distinct types for type-safe socket usage:

- **`Socket`** -- an unconnected socket for sending/receiving to arbitrary addresses.
- **`ConnectedSocket`** -- a connected socket for communicating with a single peer.

## Usage

@example('networking/udp-echo.php')

## Design

### Unconnected vs. Connected Sockets

An unconnected `Socket` can send to and receive from any address using `sendTo()` and `receiveFrom()`. When you call `connect()` on it, a `ConnectedSocket` is returned and the `Socket` object is closed. The connected socket uses simpler `send()` and `receive()` methods since the peer is fixed.

With ext-sockets, `connect()` connects the same OS socket in place, so the connected socket keeps the socket's options, its local address and port, and the datagrams already queued on it. It connects to the address a new socket would: an IP literal, or the first address of a host name, in the system's order, that the socket's family reaches (an IPv4 socket skips IPv6 addresses). An IPv6 socket bound to `::` reaches an IPv4 address through its IPv4-mapped form, such as `::ffff:127.0.0.1`, which its local address then shows. Without the extension, a new socket bound to the same local address takes its place: it starts without those options and queued datagrams, and another process may take the port in between. The same replacement, by an IPv4 socket on a new port, runs for an IPv4 address on an IPv6 socket that is IPv6-only or bound to any address but `::`, and a connect that fails in place is replaced too, so it fails, or succeeds, as it does without the extension. An IPv4 socket cannot connect to an IPv6 address.

@example('networking/udp-connected.php')

You can also create a connected socket directly. `UDP\connect()` selects the local socket family from the target, so IPv6 targets work without an explicit local bind:

@example('networking/udp-connect-shorthand.php')

### Receive Errors

On POSIX systems with ext-sockets, a `ConnectedSocket` receives through the extension, so a failed `receive()` or `peek()` throws `Network\Exception\RuntimeException` with the errno as its code, such as `ECONNREFUSED` once the peer's port was unreachable. The socket stays usable, and the next receive waits for the next datagram. Without the extension, and on Windows, such a failure cannot be told apart from a missing datagram: a `receive()` consumes it and waits again, so bound it with a cancellation token. A `peek()` leaves the error pending on BSD systems, such as macOS, and the socket stays readable: the peek then throws `Network\Exception\RuntimeException` with 0 as its code, as every peek does until a receive consumes the error.

A receive or peek that finds no datagram after its socket became readable, as when another receive took the datagram first, waits for the next one. Closing a socket fails every call waiting on it with `IO\Exception\AlreadyClosedException`.

### Configuration

`UDP\Socket::bind()` accepts a `BindConfiguration` to control address reuse, port reuse, and broadcast. Configuration objects are immutable with `with*` builder methods.

## Examples

### Echo Server

@example('networking/udp-echo-server.php')

### Peek

@example('networking/udp-peek.php')

See `src/Psl/UDP/` for the full API.
