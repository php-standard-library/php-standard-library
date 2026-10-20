<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Connection;

use Closure;
use Override;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\HTTP\Client;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception;
use Psl\HTTP\Client\Internal;
use Psl\HTTP\Client\Internal\H1\H1Connection;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Client\Internal\HttpTunnel;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\Network\Exception\RuntimeException;
use Psl\Socks;
use Psl\TCP;
use Psl\TLS;
use Psl\TLS\Exception\HandshakeFailedException;
use Psl\Unix;
use Throwable;

use function array_key_exists;
use function array_key_first;
use function array_pop;
use function array_shift;
use function count;
use function hash;
use function in_array;
use function serialize;

/**
 * Connection-pooling connector that reuses HTTP/1.x connections and shares HTTP/2 sessions.
 *
 * This is the default {@see ConnectorInterface} implementation used by {@see Client\Client}.
 * It keys connections by origin, SNI host, transport settings, and protocol versions, and
 * automatically selects the appropriate protocol based on the resolved protocol versions
 * and ALPN negotiation results.
 *
 * ## HTTP/1.x connection pooling
 *
 * For HTTP/1.x connections, the connector maintains an idle pool of up to
 * {@see MAX_IDLE_CONNECTIONS} TCP streams per origin. When a request completes, the
 * underlying stream is returned to the idle pool (via a release callback) for reuse by
 * subsequent requests to the same origin. Idle connections that are closed by the server
 * or garbage-collected are automatically pruned on checkout. When the idle pool is full,
 * the oldest connections are evicted.
 *
 * Idle streams are held as strong references to keep them alive for reuse.
 * Streams that are closed by the server are pruned on checkout.
 *
 * ## HTTP/2 session sharing
 *
 * HTTP/2 multiplexes multiple requests over a single TCP connection. The connector
 * maintains one {@see H2Session} per origin and returns a new {@see H2Connection}
 * (representing one HTTP/2 stream) for each request. All concurrent requests to the
 * same origin share the same session and underlying TCP socket.
 *
 * If the session is closed (e.g., due to a GOAWAY frame or connection error), it is
 * removed from the pool and a new connection is established on the next request.
 *
 * ## Connection coalescing for HTTPS
 *
 * When multiple fibers concurrently request connections to the same HTTPS origin, the
 * first fiber performs the TLS handshake and ALPN negotiation. If HTTP/2 is negotiated,
 * waiting fibers reuse the established session instead of opening redundant connections.
 * If HTTP/1.1 is negotiated, waiting fibers are notified to establish their own
 * connections.
 *
 * This coalescing prevents a thundering-herd of TLS handshakes when many requests target
 * the same origin simultaneously.
 *
 * ## Unix socket connections
 *
 * When the configuration specifies a Unix domain socket, the connector bypasses DNS
 * resolution and TCP connection, connecting directly to the socket. HTTP/2
 * prior-knowledge mode is used when the resolved protocol versions contain only
 * {@see ProtocolVersion::V20}.
 *
 * ## Protocol version resolution
 *
 * Protocol version resolution follows the rules documented in {@see ConnectorInterface}.
 * For HTTPS connections, ALPN tokens ("h2", "http/1.1") are derived from the resolved
 * versions and advertised during the TLS handshake. The server's ALPN selection
 * determines which connection type is created.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-3.2 HTTP/2 Connection Preface
 * @link https://datatracker.ietf.org/doc/html/rfc9113#section-3.4 HTTP/2 Prior Knowledge
 * @link https://datatracker.ietf.org/doc/html/rfc7301 TLS ALPN Extension
 *
 * @api
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final class PooledConnector implements ConnectorInterface
{
    /**
     * The underlying TCP connector used to establish raw TCP connections.
     *
     * When no custom connector is provided, a default {@see TCP\Connector} is
     * created with TCP_NODELAY enabled to reduce latency for HTTP exchanges.
     */
    private TCP\ConnectorInterface $tcpConnector;

    /**
     * Default maximum total idle HTTP/1.x connections across all origins.
     *
     * This global cap prevents unbounded memory growth when the client
     * communicates with many distinct origins.
     */
    private const int DEFAULT_MAX_IDLE_CONNECTIONS = 256;

    /**
     * Default maximum idle HTTP/1.x connections retained per origin.
     *
     * Limits how many idle streams a single origin can hold in the pool.
     * Once reached, the oldest idle connection for that origin is evicted.
     */
    private const int DEFAULT_MAX_IDLE_CONNECTIONS_PER_HOST = 32;

    /**
     * Maximum total idle HTTP/1.x connections across all origins.
     *
     * When the total number of idle connections exceeds this limit, the
     * oldest connections (across all origins) are evicted until the count
     * is within the limit.
     */
    private int $maxIdleConnections;

    /**
     * Maximum idle HTTP/1.x connections retained per origin.
     *
     * When the number of idle connections for a single origin exceeds
     * this limit, the oldest connections for that origin are evicted.
     */
    private int $maxIdleConnectionsPerHost;

    /**
     * Idle HTTP/1.x streams available for reuse, keyed by origin string.
     *
     * Streams are held as strong references to keep them alive for reuse.
     * Closed streams are pruned during checkout.
     *
     * @var array<string, list<list{Network\StreamInterface, ConnectionMetadata}>>
     */
    private array $h1Idle = [];

    /**
     * Active HTTP/2 sessions keyed by origin string.
     *
     * Each entry contains the session, local address, peer address, and TLS
     * state. Multiple {@see H2Connection} instances may share the same session.
     *
     * @var array<string, list{H2Session, ConnectionMetadata}>
     */
    private array $h2Sessions = [];

    /**
     * Pending connection awaitables for HTTP/2 session coalescing.
     *
     * When one fiber is establishing an HTTPS connection to an origin, other fibers
     * targeting the same origin wait on this awaitable instead of opening redundant
     * connections. If HTTP/2 is negotiated, the waiting fibers reuse the session.
     *
     * @var array<string, Async\Awaitable<list{H2Session, ConnectionMetadata}>>
     */
    private array $pendingConnections = [];

    /**
     * @param null|TCP\ConnectorInterface $tcpConnector TCP connector; a default is created if null.
     * @param int $maxIdleConnections Maximum total idle H1 connections across all origins.
     * @param int $maxIdleConnectionsPerHost Maximum idle H1 connections per origin.
     */
    public function __construct(
        null|TCP\ConnectorInterface $tcpConnector = null,
        int $maxIdleConnections = self::DEFAULT_MAX_IDLE_CONNECTIONS,
        int $maxIdleConnectionsPerHost = self::DEFAULT_MAX_IDLE_CONNECTIONS_PER_HOST,
    ) {
        $this->tcpConnector = $tcpConnector ?? new TCP\Connector(new TCP\ConnectConfiguration(noDelay: true));
        $this->maxIdleConnections = $maxIdleConnections;
        $this->maxIdleConnectionsPerHost = $maxIdleConnectionsPerHost;
    }

    /**
     * Close all idle HTTP/1.x connections and gracefully shut down HTTP/2 sessions.
     *
     * Called when the connector is garbage-collected or explicitly destroyed.
     * All pooled HTTP/1.x streams are closed, and all active HTTP/2 sessions
     * are marked as closed to prevent further stream creation. An I/O failure
     * while closing one stream does not prevent closing the remaining streams.
     */
    public function __destruct()
    {
        foreach ($this->h1Idle as $streams) {
            foreach ($streams as [$stream, $_]) {
                try {
                    $stream->close();
                } catch (IO\Exception\RuntimeException) {
                    continue;
                }
            }
        }

        $this->h1Idle = [];

        foreach ($this->h2Sessions as [$session, $_]) {
            $session->markClosed();
        }

        $this->h2Sessions = [];
    }

    /**
     * Resolve the effective TCP connector, wrapping it in a SOCKS5 proxy connector if configured.
     *
     * @param ClientConfiguration $configuration The client configuration that may specify a SOCKS5 proxy.
     *
     * @return TCP\ConnectorInterface The connector to use for TCP connections.
     */
    private function resolveTcpConnector(ClientConfiguration $configuration): TCP\ConnectorInterface
    {
        if ($configuration->socksConfiguration !== null) {
            return new Socks\Connector($this->tcpConnector, $configuration->socksConfiguration);
        }

        return $this->tcpConnector;
    }

    /**
     * Obtain a connection from the pool or establish a new one.
     *
     * The connection strategy depends on the origin and negotiated protocol:
     *
     * 1. **Unix socket**: Reuses a matching H2 session or connects directly to the socket.
     * 2. **Existing HTTP/2 session**: If an active session exists for the origin, a
     *    new stream is created on it without any network I/O.
     * 3. **Pending HTTPS connection**: If another fiber is already connecting to the
     *    same HTTPS origin, waits for it to complete. If HTTP/2 was negotiated, shares
     *    the session; otherwise, establishes a new connection.
     * 4. **Idle HTTP/1.x connection**: If an idle stream is available in the pool,
     *    checks it out for reuse.
     * 5. **New connection**: Establishes a new TCP (+TLS for HTTPS) connection and
     *    negotiates the protocol via ALPN.
     *
     * @param Origin $origin The target origin (scheme, host, port) to connect to.
     * @param Request $request The HTTP request for protocol version resolution.
     * @param ClientConfiguration $configuration Client configuration for protocol preferences, TLS settings, and Unix socket path.
     * @param CancellationTokenInterface $cancellation Token to cancel the connection attempt.
     *
     * @return ConnectionInterface A pooled or freshly established connection ready for an HTTP exchange.
     *
     * @throws Exception\ProtocolException If the requested protocol version is not supported or not permitted by the configuration.
     * @throws RuntimeException If the TCP connection fails.
     * @throws HandshakeFailedException If the TLS handshake fails.
     * @throws CancelledException If the cancellation token fires during any stage.
     */
    #[Override]
    public function connect(
        Origin $origin,
        Request $request,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): ConnectionInterface {
        $cancellation->throwIfCancelled();

        $versions = Internal\resolve_protocol_versions($request, $configuration);

        $key = self::poolKey($origin, $versions, $configuration);

        $this->pruneClosedH2Sessions();

        if (array_key_exists($key, $this->h2Sessions)) {
            [$session, $metadata] = $this->h2Sessions[$key];
            if ($session->isClosed()) {
                unset($this->h2Sessions[$key]);
            } else {
                return new H2Connection($session, $metadata, $this->h2Reconnect(...));
            }
        }

        if ($configuration->unixSocket !== null) {
            return $this->connectUnix($configuration->unixSocket, $key, $versions, $cancellation, $configuration);
        }

        if (array_key_exists($key, $this->pendingConnections)) {
            [$session, $metadata] = $this->pendingConnections[$key]->await();
            return new H2Connection($session, $metadata, $this->h2Reconnect(...));
        }

        $supportsH1 =
            in_array(ProtocolVersion::V11, $versions, strict: true)
            || in_array(ProtocolVersion::V10, $versions, strict: true);

        if ($supportsH1) {
            $h1 = $this->checkoutH1($key);
            if ($h1 !== null) {
                return new H1Connection($h1[0], $h1[1], $this->h1ReleaseCallback($key));
            }
        }

        if ($origin->scheme === 'https') {
            return $this->connectCoalesced($origin, $key, $versions, $cancellation, $configuration);
        }

        // h2c (prior knowledge) - needs coalescing like HTTPS so concurrent
        // fibers share the multiplexed session instead of each opening a connection.
        if ($versions === [ProtocolVersion::V20]) {
            return $this->connectCoalesced($origin, $key, $versions, $cancellation, $configuration);
        }

        return $this->connectTCP($origin, $key, $versions, $cancellation, $configuration);
    }

    /**
     * Keep transport credentials and protocol settings within their own pool.
     *
     * @param list<ProtocolVersion> $versions
     */
    private static function poolKey(Origin $origin, array $versions, ClientConfiguration $configuration): string
    {
        $proxy = $configuration->proxyConfiguration;

        return hash('sha256', serialize([
            $origin,
            $versions,
            $configuration->tlsConfiguration,
            $configuration->h2ClientConfiguration,
            $configuration->unixSocket,
            $configuration->socksConfiguration,
            $proxy === null
                ? null
                : [$proxy->url->toString(), $proxy->authorization, $proxy->sni, $proxy->skipProxyFor],
        ]));
    }

    /**
     * Establish a coalesced connection with HTTP/2 session sharing.
     *
     * Used for both HTTPS (TLS + ALPN) and h2c (plaintext prior-knowledge).
     * Registers a pending connection awaitable so other fibers targeting the same
     * origin can wait for the result instead of opening redundant connections.
     *
     * If HTTP/2 is negotiated (or known via prior-knowledge), the session is
     * shared with waiting fibers. If HTTP/1.1 is negotiated, waiting fibers
     * are notified via an error to establish their own independent connections.
     *
     * This coalescing prevents a thundering-herd of TLS handshakes when many
     * concurrent fibers target the same origin simultaneously.
     *
     * @param Origin $origin The target origin (scheme, host, port).
     * @param string $key The pool key derived from the origin string.
     * @param list<ProtocolVersion> $versions Resolved protocol versions.
     * @param CancellationTokenInterface $cancellation Token to cancel the connection.
     * @param ClientConfiguration $configuration Client configuration for TLS and proxy settings.
     *
     * @return ConnectionInterface An HTTP/1.x or HTTP/2 connection.
     */
    private function connectCoalesced(
        Origin $origin,
        string $key,
        array $versions,
        CancellationTokenInterface $cancellation,
        ClientConfiguration $configuration,
    ): ConnectionInterface {
        /** @var Async\Deferred<array{H2Session, ConnectionMetadata}> $deferred */
        $deferred = new Async\Deferred();
        $awaitable = $deferred->getAwaitable();
        $awaitable->ignore();
        $this->pendingConnections[$key] = $awaitable;

        try {
            $connection = $origin->scheme === 'https'
                ? $this->connectTLS($origin, $key, $versions, $cancellation, $configuration)
                : $this->connectTCP($origin, $key, $versions, $cancellation, $configuration);

            if ($connection instanceof H2Connection && array_key_exists($key, $this->h2Sessions)) {
                // H2 - share the session with waiting fibers.
                [$session, $metadata] = $this->h2Sessions[$key];
                $deferred->complete([$session, $metadata]);
            } else {
                // H1 over TLS - connections can't be shared. Error
                // waiting fibers so they establish their own.
                $deferred->error(new RuntimeException('ALPN negotiated HTTP/1.1; connection cannot be shared.'));
            }

            return $connection;
        } catch (Throwable $e) {
            $deferred->error($e);
            throw $e;
        } finally {
            unset($this->pendingConnections[$key]);
        }
    }

    /**
     * Establish a plaintext TCP connection and create the appropriate connection type.
     *
     * Applies HTTP forward proxying when configured and the target host is not in
     * the bypass list. For HTTP/2 prior-knowledge mode (only {@see ProtocolVersion::V20}
     * in the version list), an H2 session is initialized directly on the TCP stream
     * and registered in the session pool. Otherwise, an HTTP/1.x connection is created
     * with a release callback to return the stream to the idle pool when done.
     *
     * @param Origin $origin The target origin (scheme, host, port).
     * @param string $key The pool key derived from the origin string.
     * @param list<ProtocolVersion> $versions Resolved protocol versions determining the connection type.
     * @param CancellationTokenInterface $cancellation Token to cancel the connection.
     * @param ClientConfiguration $configuration Client configuration for proxy settings.
     *
     * @return ConnectionInterface An HTTP/1.x or HTTP/2 (h2c) connection.
     */
    private function connectTCP(
        Origin $origin,
        string $key,
        array $versions,
        CancellationTokenInterface $cancellation,
        ClientConfiguration $configuration,
    ): ConnectionInterface {
        $connector = $this->resolveTcpConnector($configuration);

        $proxyConfiguration = $configuration->proxyConfiguration;
        if ($proxyConfiguration !== null && Internal\should_tunnel($origin->host, $proxyConfiguration->skipProxyFor)) {
            return $this->connectViaForwardProxy($connector, $proxyConfiguration, $configuration, $cancellation);
        }

        $stream = $connector->connect($origin->host, $origin->port, $cancellation);
        $metadata = new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null);

        if ($versions === [ProtocolVersion::V20]) {
            return $this->createH2Connection($stream, $metadata, $key, $configuration);
        }

        return new H1Connection($stream, $metadata, $this->h1ReleaseCallback($key));
    }

    /**
     * Connect to an HTTP forward proxy for plaintext HTTP requests.
     *
     * @see Connector::connectViaForwardProxy() for the non-pooled equivalent.
     */
    private function connectViaForwardProxy(
        TCP\ConnectorInterface $connector,
        Client\ProxyConfiguration $proxyConfiguration,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation,
    ): H1Connection {
        $proxyHost = $proxyConfiguration->url->authority->host->toString();
        $proxyPort =
            $proxyConfiguration->url->authority->port ?? ($proxyConfiguration->url->scheme === 'https' ? 443 : 80);
        $proxyTls = $proxyConfiguration->url->scheme === 'https';

        $stream = $connector->connect($proxyHost, $proxyPort, $cancellation);

        if ($proxyTls) {
            $tlsConnector = new TLS\Connector($configuration->tlsConfiguration);
            $stream = $tlsConnector->connect($stream, $proxyConfiguration->sni ?? $proxyHost, $cancellation);
        }

        $metadata = new ConnectionMetadata(
            $stream->getLocalAddress(),
            $stream->getPeerAddress(),
            $proxyTls && $stream instanceof TLS\StreamInterface ? $stream->getState() : null,
        );

        return new H1Connection(
            $stream,
            $metadata,
            onRelease: null,
            isForwardProxy: true,
            proxyAuthorization: $proxyConfiguration->authorization,
        );
    }

    /**
     * Establish a TLS connection with ALPN negotiation and create the appropriate connection type.
     *
     * Configures ALPN protocol tokens based on the resolved versions ("h2" for HTTP/2,
     * "http/1.1" for HTTP/1.x), performs the TLS handshake via
     * {@see ClientConfiguration::$tlsConfiguration}, and creates the appropriate
     * connection type based on the server's ALPN selection:
     *
     * - If "h2" is negotiated, an HTTP/2 session is created and registered in the
     *   session pool for sharing with future requests to the same origin.
     * - Otherwise, an HTTP/1.x connection is created with a release callback to
     *   return the stream to the idle pool when done.
     *
     * @param Origin $origin The target origin (scheme, host, port).
     * @param string $key The pool key derived from the origin string.
     * @param list<ProtocolVersion> $versions Resolved protocol versions used to derive ALPN tokens.
     * @param CancellationTokenInterface $cancellation Token to cancel the connection or handshake.
     * @param ClientConfiguration $configuration Client configuration for TLS and proxy settings.
     *
     * @return ConnectionInterface An HTTP/1.x or HTTP/2 connection, depending on ALPN negotiation.
     */
    private function connectTLS(
        Origin $origin,
        string $key,
        array $versions,
        CancellationTokenInterface $cancellation,
        ClientConfiguration $configuration,
    ): ConnectionInterface {
        $tlsConfig = $configuration->tlsConfiguration;

        $alpnProtocols = [];
        if (in_array(ProtocolVersion::V20, $versions, strict: true)) {
            $alpnProtocols[] = 'h2';
        }

        if (
            in_array(ProtocolVersion::V11, $versions, strict: true)
            || in_array(ProtocolVersion::V10, $versions, strict: true)
        ) {
            $alpnProtocols[] = 'http/1.1';
        }

        if ($alpnProtocols !== []) {
            $tlsConfig = $tlsConfig->withAlpnProtocols($alpnProtocols);
        }

        $connector = $this->resolveTcpConnector($configuration);

        if (
            $configuration->proxyConfiguration !== null
            && Internal\should_tunnel($origin->host, $configuration->proxyConfiguration->skipProxyFor)
        ) {
            $tcpStream = HttpTunnel::connect(
                $connector,
                $configuration->proxyConfiguration,
                $origin->host,
                $origin->port,
                $cancellation,
                $configuration->tlsConfiguration,
            );
        } else {
            $tcpStream = $connector->connect($origin->host, $origin->port, $cancellation);
        }

        $tlsConnector = new TLS\Connector($tlsConfig);
        $stream = $tlsConnector->connect($tcpStream, $origin->sniHost ?? $origin->host, $cancellation);
        $metadata = new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), $stream->getState());

        if ($stream->getState()->alpnProtocol === 'h2' && in_array(ProtocolVersion::V20, $versions, strict: true)) {
            return $this->createH2Connection($stream, $metadata, $key, $configuration);
        }

        return new H1Connection($stream, $metadata, $this->h1ReleaseCallback($key));
    }

    /**
     * Connect to a Unix domain socket and create the appropriate connection type.
     *
     * Bypasses DNS resolution and TCP connection entirely, connecting directly to
     * the specified socket path. H2 sessions use the same pool key as TCP sessions;
     * H1 connections are not pooled.
     *
     * For HTTP/2 prior-knowledge mode (only {@see ProtocolVersion::V20} in the
     * version list), an H2 session is initialized on the Unix stream and registered
     * in the session pool. Otherwise, an HTTP/1.x connection is created without a
     * release callback (no pooling for Unix socket H1 connections).
     *
     * @param non-empty-string $path Path to the Unix domain socket.
     * @param list<ProtocolVersion> $versions Resolved protocol versions determining the connection type.
     * @param CancellationTokenInterface $cancellation Token to cancel the connection.
     * @param ClientConfiguration $configuration Client configuration for H2 session settings.
     *
     * @return ConnectionInterface An HTTP/1.x or HTTP/2 connection over the Unix socket.
     */
    private function connectUnix(
        string $path,
        string $key,
        array $versions,
        CancellationTokenInterface $cancellation,
        ClientConfiguration $configuration,
    ): ConnectionInterface {
        $stream = Unix\connect($path, $cancellation);
        $metadata = new ConnectionMetadata($stream->getLocalAddress(), $stream->getPeerAddress(), null);

        if ($versions === [ProtocolVersion::V20]) {
            return $this->createH2Connection($stream, $metadata, $key, $configuration);
        }

        return new H1Connection($stream, $metadata);
    }

    /**
     * Initialize an HTTP/2 session on a network stream and register it in the session pool.
     *
     * Creates an {@see H2Session} from the provided stream and H2 configuration,
     * prunes any closed sessions, evicts the oldest session if the pool exceeds
     * {@see $maxIdleConnections}, stores the new session keyed by origin, and
     * returns a new {@see H2Connection} representing one stream on the session.
     *
     * The returned connection carries a reconnect callback ({@see h2Reconnect()})
     * so that a session closed before an exchange starts can be replaced.
     * Failed exchanges are not replayed.
     *
     * @param Network\StreamInterface $stream The underlying TCP, TLS, or Unix stream.
     * @param ConnectionMetadata $metadata Transport-level metadata for the connection.
     * @param string $key The pool key derived from the origin string.
     * @param ClientConfiguration $configuration Client configuration providing H2 session settings.
     *
     * @return H2Connection A connection representing one HTTP/2 stream on the pooled session.
     */
    private function createH2Connection(
        Network\StreamInterface $stream,
        ConnectionMetadata $metadata,
        string $key,
        ClientConfiguration $configuration,
    ): H2Connection {
        $session = new H2Session($stream, $configuration->h2ClientConfiguration);
        $this->pruneClosedH2Sessions();
        while (count($this->h2Sessions) >= $this->maxIdleConnections) {
            $oldestKey = array_key_first($this->h2Sessions);
            if ($oldestKey === null) {
                break;
            }

            [$oldSession] = $this->h2Sessions[$oldestKey];
            $oldSession->markClosed();
            unset($this->h2Sessions[$oldestKey]);
        }

        $this->h2Sessions[$key] = [$session, $metadata];

        return new H2Connection($session, $metadata, $this->h2Reconnect(...));
    }

    /**
     * Remove all closed HTTP/2 sessions from the session pool.
     *
     * Iterates the session pool and removes entries whose session has been
     * closed (e.g., due to a GOAWAY frame, connection error, or explicit
     * shutdown). Called before creating new sessions and before checking
     * for existing sessions to ensure stale entries do not accumulate.
     */
    private function pruneClosedH2Sessions(): void
    {
        foreach ($this->h2Sessions as $key => [$session]) {
            if (!$session->isClosed()) {
                continue;
            }

            unset($this->h2Sessions[$key]);
        }
    }

    /**
     * Reconnect callback for HTTP/2 connections whose session has died.
     *
     * When an HTTP/2 session is terminated (e.g., by a GOAWAY frame or
     * connection error), {@see H2Connection::exchange()} calls this method
     * only before starting a new exchange. It does not replay a failed request.
     *
     * Delegates to {@see connect()}, which may return an existing pooled
     * session, a coalesced session from another fiber, or a brand-new
     * connection depending on the current pool state.
     *
     * @param Request $request The HTTP request being exchanged.
     * @param ClientConfiguration $configuration Client configuration for the reconnection.
     * @param CancellationTokenInterface $cancellation Token to cancel the reconnection.
     *
     * @return ConnectionInterface A fresh, exchange-ready connection.
     */
    private function h2Reconnect(
        Request $request,
        ClientConfiguration $configuration,
        CancellationTokenInterface $cancellation,
    ): ConnectionInterface {
        $url = $request->url ?? throw Exception\RequestException::forMissingUrl();
        $origin = Origin::fromUrl($url);

        return $this->connect($origin, $request, $configuration, $cancellation);
    }

    /**
     * Create a release callback that returns an HTTP/1.x stream to the idle pool.
     *
     * The returned closure is passed to {@see H1Connection} and invoked by
     * {@see ConnectionInterface::finalize()} when the response body is fully
     * consumed. The closure checks whether the stream is still open before
     * returning it to the pool. If the per-origin idle limit
     * ({@see $maxIdleConnectionsPerHost}) is exceeded, the oldest idle
     * connection for that origin is evicted. After adding the stream, the
     * global idle limit ({@see $maxIdleConnections}) is enforced.
     *
     * @param string $key The pool key (origin string) for the connection.
     *
     * @return (Closure(Network\StreamInterface, ConnectionMetadata): void) A closure that returns the stream to the pool.
     */
    private function h1ReleaseCallback(string $key): Closure
    {
        return function (Network\StreamInterface $stream, ConnectionMetadata $metadata) use ($key): void {
            if ($stream->isClosed()) {
                return;
            }

            $this->h1Idle[$key] ??= [];
            while (count($this->h1Idle[$key]) >= $this->maxIdleConnectionsPerHost) {
                $evicted = array_shift($this->h1Idle[$key]);
                if ($evicted !== null) {
                    $evicted[0]->close();
                }
            }

            $this->h1Idle[$key][] = [$stream, $metadata];

            $this->evictExcessIdleConnections();
        };
    }

    /**
     * Evict the oldest idle HTTP/1.x connections globally until the total is within the limit.
     *
     * Iterates origins in insertion order and removes the oldest (first) idle
     * connection from each origin, one at a time, until the total number of
     * idle connections across all origins is at or below {@see $maxIdleConnections}.
     * Empty origin buckets are removed from the pool during eviction.
     */
    private function evictExcessIdleConnections(): void
    {
        $total = 0;
        foreach ($this->h1Idle as $streams) {
            $total += count($streams);
        }

        while ($total > $this->maxIdleConnections) {
            foreach ($this->h1Idle as $originKey => $streams) {
                if ($streams === []) {
                    unset($this->h1Idle[$originKey]);
                    continue;
                }

                $evicted = array_shift($this->h1Idle[$originKey]);
                if (null !== $evicted) {
                    $evicted[0]->close();
                }

                if ($this->h1Idle[$originKey] === []) {
                    unset($this->h1Idle[$originKey]);
                }

                $total--;
                break;
            }

            if ($this->h1Idle === []) {
                break;
            }
        }
    }

    /**
     * Attempt to check out an idle HTTP/1.x stream from the pool for the given origin.
     *
     * Pops streams from the idle pool in LIFO order (most recently returned first)
     * and validates each candidate before returning it. A stream is considered
     * unusable and discarded if:
     *
     * - It has been closed (e.g., by the server or a timeout).
     * - A non-blocking read probe returns data or EOF, indicating the server
     *   sent unexpected data or closed the connection while it was idle.
     *
     * Returns the first usable stream and its metadata, or {@see null} if no
     * usable stream is available for the given origin.
     *
     * @param string $key The pool key (origin string) to check out from.
     *
     * @return null|list{Network\StreamInterface, ConnectionMetadata} The checked-out stream and metadata, or {@see null}.
     */
    private function checkoutH1(string $key): null|array
    {
        while (array_key_exists($key, $this->h1Idle) && $this->h1Idle[$key] !== []) {
            [$stream, $metadata] = array_pop($this->h1Idle[$key]);

            if ($this->h1Idle[$key] === []) {
                unset($this->h1Idle[$key]);
            }

            if ($stream->isClosed()) {
                continue;
            }

            $probe = $stream->tryRead(1);
            if ($probe !== '' || $stream->reachedEndOfDataSource()) {
                $stream->close();
                continue;
            }

            return [$stream, $metadata];
        }

        return null;
    }
}
