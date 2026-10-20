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

### Configuration

`UDP\Socket::bind()` accepts a `BindConfiguration` to control address reuse, port reuse, and broadcast. Configuration objects are immutable with `with*` builder methods.

## Examples

### Echo Server

@example('networking/udp-echo-server.php')

### Peek

@example('networking/udp-peek.php')

See `src/Psl/UDP/` for the full API.
