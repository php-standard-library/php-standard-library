# Unix

The `Unix` component provides a non-blocking API for Unix domain socket connections, built on top of PSL's IO and Network abstractions.

Unix domain sockets provide local inter-process communication without the overhead of TCP's network stack. Not available on Windows.

`Unix\connect()`, `Unix\listen()`, `Unix\Socket::bind()` and `Unix\Socket::connect()` throw `Network\Exception\InvalidArgumentException` for a socket path containing a NUL byte, which the operating system would otherwise silently truncate. A path starting with a NUL byte names a socket in the Linux abstract namespace instead, where every byte is part of the name; all four pass it through unchanged. They also throw it for a path longer than the system can hold, which PHP would otherwise cut short: 103 bytes on macOS and the BSDs, 107 on Linux, where a name in the abstract namespace may have 108, its leading NUL byte included.

## Addresses

A Unix stream reports the names its two sockets really have. A socket bound to a path reports that path; a socket that is not bound, as a client usually is, reports the unnamed address `unix://<anonymous>`. So a client's `getPeerAddress()` is the listener's path and its `getLocalAddress()` is unnamed, and a stream that a listener accepted reports the listener's path as its local address and the client's name, or the unnamed address, as its peer address.

## Binding a Client

`Unix\Socket::bind()` names a socket before it listens or connects. A client bound this way connects from that path, so the listener sees the path as the accepted stream's peer address. Binding before connecting needs ext-sockets, as PHP's streams do not bind a Unix client; without the extension, `Unix\Socket::connect()` on a bound socket throws `Network\Exception\RuntimeException` before it creates a socket. The file the bind creates stays after the socket closes, as a listener's does.

## Usage

@example('networking/unix-echo.php')

## Configuration

`Unix\listen()` accepts a `ListenConfiguration` to control backlog (default 512) and idle connections (default 256). Configuration objects are immutable with `with*` builder methods.

## Examples

### Echo Server

@example('networking/unix-echo-server.php')

### Client with Cancellation

@example('networking/unix-client-timeout.php')

### Concurrent Connections

@example('networking/unix-concurrent.php')

See `src/Psl/Unix/` for the full API.
