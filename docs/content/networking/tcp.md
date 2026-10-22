# TCP

The `TCP` component provides a non-blocking API for TCP client and server connections, built on top of PSL's IO and Network abstractions.

## Usage

@example('networking/tcp-echo.php')

## Design

### ConnectorInterface

All TCP connectors implement `ConnectorInterface`, making them interchangeable and composable. The built-in connectors are:

- **`Connector`** -- the default connector wrapping `TCP\connect()`.
- **`RetryConnector`** -- wraps any connector with exponential backoff retry on failure. Failures implementing `Network\Exception\NonRetryableExceptionInterface`, such as a rejected TLS certificate (`TLS\Exception\PeerVerificationException`), are rethrown at once.
- **`StaticConnector`** -- redirects all connections to a fixed host and port (useful for testing).

### Connect failures

`TCP\connect()` throws `Network\Exception\RuntimeException` when the connection cannot be made. With `ext-sockets` installed, its code is the operating system's error number, such as `ECONNREFUSED` for a refused connection; without the extension it is 0 for a connection that fails after the socket was created. The `network` package lists `ext-sockets` as a suggestion.

### SocketPool

`SocketPool` reuses idle TCP connections. Checked-in connections stay alive for a configurable idle timeout before being closed.

@example('networking/tcp-socket-pool.php')

### Configuration

`TCP\listen()` and `TCP\connect()` accept configuration objects that control socket behavior:

- **`ListenConfiguration`** -- noDelay, reuseAddress, reusePort, backlog (default 512), idleConnections (default 256), bindTo
- **`ConnectConfiguration`** -- noDelay, bindTo

The `bindTo` option on both configuration objects allows binding to a specific local address before connecting or listening. This is useful for selecting a particular network interface or source IP:

@example('networking/tcp-bind-to.php')

All configuration objects are immutable and provide `with*` builder methods for fluent configuration:

@example('networking/tcp-configuration.php')

@example('networking/tcp-backlog.php')

### Low-Level Socket (Deprecated)

> **Deprecated**: `TCP\Socket` is deprecated in favor of the `bindTo` option on `ConnectConfiguration` and `ListenConfiguration`. It will be removed in PSL 7.0.

`Socket` previously provided fine-grained control over socket creation via a bind-then-connect/listen pattern. This is now replaced by the `bindTo` configuration option. See the example below for the migration pattern:

@example('networking/tcp-low-level-socket.php')

## Examples

### Echo Server

@example('networking/tcp-echo-server.php')

### Client with Cancellation

@example('networking/tcp-client-timeout.php')

### Retry with Backoff

@example('networking/tcp-retry-backoff.php')

### Cancellable Accept

`ListenerInterface::accept()` accepts a `CancellationTokenInterface`, allowing you to cancel waiting for connections, for example during a graceful shutdown:

@example('networking/tcp-cancellable-accept.php')

### Restricted Listener

`RestrictedListener` wraps any `ListenerInterface` and restricts connections to a set of allowed `IP\Address` and `CIDR\Block` entries. Rejected connections are closed silently.

@example('networking/tcp-restricted-listener.php')

### Composite Listener

`Network\CompositeListener` accepts connections from multiple listeners concurrently through a single `accept()` call. Each inner listener has at most one accept in flight, in its own fiber, and accepted connections are handed to `accept()` callers in completion order.

If an inner listener fails to accept a connection (for example, when a TLS handshake fails), `accept()` rethrows that error and the composite listener keeps accepting from every listener. Inner listeners that have been stopped are skipped, and `accept()` throws `AlreadyStoppedException` once all of them are stopped. Cancelling the last waiting `accept()` call also cancels the in-flight inner accepts. Closing the composite listener cancels them too, makes every waiting `accept()` call throw `AlreadyStoppedException`, then closes the connections left in its backlog and all inner listeners. It attempts every one of those closes, even when one fails, and then throws the first failure.

@example('networking/composite-listener.php')

See `src/Psl/TCP/` for the full API.
