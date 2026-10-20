<?php

declare(strict_types=1);

namespace Psl\HTTP\Client;

use Closure;
use Psl\DateTime\Duration;
use Psl\HTTP\Message;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\HTTP\Message\Response;
use Psl\Socks;
use Psl\TLS;
use Psl\URL\URL;

/**
 * Client-level configuration governing transport behavior, protocol preferences, and response limits.
 *
 * This immutable value object holds all settings that influence how the HTTP client
 * establishes connections, negotiates protocols, and enforces response limits. A default
 * configuration is suitable for most use cases; individual settings can be overridden
 * using the `with*()` methods, which return new instances.
 *
 * The configuration can be provided at two levels:
 *
 * 1. **Client default**: Passed to the {@see Client} constructor and used for all
 *    requests unless overridden.
 * 2. **Per-request override**: Passed to {@see ClientInterface::send()} to override
 *    the client default for a single request.
 *
 * ## Protocol version negotiation
 *
 * The {@see $protocolVersions} list controls which HTTP versions the client is willing
 * to use, ordered from most preferred to least preferred. During TLS handshake, these
 * map to ALPN protocol tokens ("h2" for HTTP/2, "http/1.1" for HTTP/1.1). The server
 * selects the protocol from the advertised list. For plaintext connections, only
 * HTTP/1.x or h2c (HTTP/2 prior knowledge) is possible.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9110 HTTP Semantics
 * @link https://datatracker.ietf.org/doc/html/rfc9112 HTTP/1.1
 * @link https://datatracker.ietf.org/doc/html/rfc9113 HTTP/2
 *
 * @api
 *
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class ClientConfiguration
{
    /**
     * @param positive-int $maxResponseHeaderSize Maximum total size of response headers in bytes, including all header field names, values, and delimiters.
     *  Responses exceeding this limit trigger a {@see Exception\ProtocolException}. Defaults to 8,192 bytes (8 KiB).
     * @param positive-int $maxResponseBodySize Maximum response body size in bytes. HTTP/2 checks this limit as DATA arrives and cancels an oversized stream,
     *  even if its body is unread. HTTP/1 checks the limit during reads. Defaults to 10,485,760 bytes (10 MiB).
     * @param null|URL $baseUrl Default base URL for resolving relative request targets. When a {@see Message\Request} has no URL set but has a request target,
     *  it is resolved against this base URL per RFC 3986 Section 5. Defaults to {@see null} (no base URL).
     * @param TLS\ClientConfiguration $tlsConfiguration TLS settings for HTTPS connections, including certificate verification, client certificates,
     *  cipher suites, and minimum protocol version.
     * @param H2ClientConfiguration $h2ClientConfiguration HTTP/2-specific session parameters such as flow-control window sizes, maximum frame size,
     *  and concurrent stream limits. These are sent to the server in the SETTINGS frame during
     *  the HTTP/2 connection preface. Only applies when an HTTP/2 connection is established.
     * @param list<ProtocolVersion> $protocolVersions Supported HTTP protocol versions in preference order (most preferred first).
     *  This drives ALPN negotiation during TLS handshake. Defaults to [HTTP/2, HTTP/1.1],
     *  preferring HTTP/2 when the server supports it.
     * @param null|non-empty-string $unixSocket Path to a Unix domain socket to connect through instead of TCP. When set,
     *  DNS resolution and TCP connection are bypassed; the client connects directly to the socket.
     *  Useful for communicating with local services (e.g., Docker daemon). Defaults to {@see null} (use TCP).
     * @param null|Socks\Configuration $socksConfiguration SOCKS5 proxy configuration for TCP-level tunneling. When set, all TCP connections are routed
     *  through the SOCKS5 proxy server. The proxy transparently tunnels TCP bytes; TLS, ALPN negotiation, and HTTP framing happen
     *  on top of the tunneled connection unchanged. Defaults to {@see null} (direct TCP connections).
     * @param null|ProxyConfiguration $proxyConfiguration HTTP proxy configuration. When set, HTTPS connections are established by first connecting to the proxy
     *  and sending an HTTP/1.1 CONNECT request to create a tunnel to the target host. Plain HTTP connections use the proxy as a forward proxy with
     *  absolute-form request targets (RFC 7230 Section 5.3.2). Defaults to {@see null} (no proxy).
     * @param null|(Closure(Response): void) $onInformationalResponse Default callback invoked for each informational (1xx) response
     *  received during an exchange. Can be overridden or extended per-request via {@see SendConfiguration::$onInformationalResponse}.
     *  {@see null} disables the callback.
     * @param null|Duration $expectContinueTimeout How long an `Expect: 100-continue` request waits for an interim response
     *  before sending the body anyway. {@see null} uses the default of one second. A final response received within the wait
     *  suppresses the body and disables connection reuse.
     */
    public function __construct(
        public int $maxResponseHeaderSize = 8_192,
        public int $maxResponseBodySize = 10_485_760,
        public null|URL $baseUrl = null,
        public TLS\ClientConfiguration $tlsConfiguration = new TLS\ClientConfiguration(),
        public H2ClientConfiguration $h2ClientConfiguration = new H2ClientConfiguration(),
        public array $protocolVersions = [ProtocolVersion::V20, ProtocolVersion::V11],
        public null|string $unixSocket = null,
        public null|Socks\Configuration $socksConfiguration = null,
        public null|ProxyConfiguration $proxyConfiguration = null,
        public null|Closure $onInformationalResponse = null,
        public null|Duration $expectContinueTimeout = null,
    ) {}

    /**
     * Return a new configuration with the given maximum response header size.
     *
     * @param positive-int $maxResponseHeaderSize Maximum total size of response headers in bytes.
     */
    public function withMaxResponseHeaderSize(int $maxResponseHeaderSize): self
    {
        return new self(
            $maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given maximum response body size.
     *
     * @param positive-int $maxResponseBodySize Maximum response body size in bytes.
     */
    public function withMaxResponseBodySize(int $maxResponseBodySize): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given base URL for resolving relative request targets.
     */
    public function withBaseUrl(null|URL $baseUrl): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given TLS settings for HTTPS connections.
     */
    public function withTlsConfiguration(TLS\ClientConfiguration $tlsConfiguration): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given HTTP/2 session parameters.
     */
    public function withH2ClientConfiguration(H2ClientConfiguration $h2ClientConfiguration): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given protocol version preferences.
     *
     * @param list<ProtocolVersion> $protocolVersions Supported protocol versions in preference order (most preferred first).
     */
    public function withProtocolVersions(array $protocolVersions): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given Unix domain socket path.
     *
     * @param null|non-empty-string $unixSocket Unix socket path to connect through, or {@see null} to use TCP.
     */
    public function withUnixSocket(null|string $unixSocket): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $unixSocket,
            $this->socksConfiguration,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given SOCKS5 proxy for TCP-level tunneling.
     */
    public function withSocksConfiguration(null|Socks\Configuration $proxy): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $proxy,
            $this->proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Return a new configuration with the given HTTP proxy configuration.
     */
    public function withProxyConfiguration(null|ProxyConfiguration $proxyConfiguration): self
    {
        return new self(
            $this->maxResponseHeaderSize,
            $this->maxResponseBodySize,
            $this->baseUrl,
            $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $proxyConfiguration,
            $this->onInformationalResponse,
            $this->expectContinueTimeout,
        );
    }

    /**
     * Merge per-request overrides into this configuration.
     *
     * Returns a new {@see ClientConfiguration} where each non-null field from
     * the {@see SendConfiguration} replaces the corresponding field in this
     * configuration. Null fields in the send configuration are inherited
     * from this configuration unchanged.
     *
     * Fields that are not overridable per-request ({@see $unixSocket},
     * {@see $socksConfiguration}, {@see $h2ClientConfiguration}) are always inherited from this configuration.
     *
     * ## Informational response callback merging
     *
     * The {@see $onInformationalResponse} callback receives special treatment:
     *
     * - If both this configuration and the send configuration define a callback,
     *   they are composed into a single callback that invokes the client-level
     *   callback first, followed by the per-request callback.
     * - If only one side defines a callback, that callback is used as-is.
     * - If neither side defines a callback, the result is {@see null}.
     *
     * @param SendConfiguration $overrides Per-request overrides to merge. Non-null fields replace the corresponding fields in this configuration.
     */
    public function withOverrides(SendConfiguration $overrides): self
    {
        $clientCb = $this->onInformationalResponse;
        $sendCb = $overrides->onInformationalResponse;
        if ($clientCb !== null && $sendCb !== null) {
            $onInformational = static function (Response $response) use ($clientCb, $sendCb): void {
                $clientCb($response);
                $sendCb($response);
            };
        } else {
            $onInformational = $sendCb ?? $clientCb;
        }

        return new self(
            $overrides->maxResponseHeaderSize ?? $this->maxResponseHeaderSize,
            $overrides->maxResponseBodySize ?? $this->maxResponseBodySize,
            $overrides->baseUrl ?? $this->baseUrl,
            $overrides->tlsConfiguration ?? $this->tlsConfiguration,
            $this->h2ClientConfiguration,
            $overrides->protocolVersions ?? $this->protocolVersions,
            $this->unixSocket,
            $this->socksConfiguration,
            $overrides->proxyConfiguration ?? $this->proxyConfiguration,
            $onInformational,
            $this->expectContinueTimeout,
        );
    }
}
