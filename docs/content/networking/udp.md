# UDP

The `UDP` component provides a non-blocking API for sending and receiving datagrams over UDP.

It uses two distinct types for type-safe socket usage:

- **`Socket`** -- an unconnected socket for sending/receiving to arbitrary addresses.
- **`ConnectedSocket`** -- a connected socket for communicating with a single peer.

## Usage

@example('networking/udp-echo.php')

## Design

### Unconnected vs. Connected Sockets

An unconnected `Socket` can send to and receive from any address using `sendTo()` and `receiveFrom()`. When you call `connect()` on it, the original socket is closed and a `ConnectedSocket` is returned. The connected socket uses simpler `send()` and `receive()` methods since the peer is fixed.

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
