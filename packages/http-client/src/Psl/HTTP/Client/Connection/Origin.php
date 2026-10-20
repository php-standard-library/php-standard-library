<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Connection;

use Psl\URL\Internal;
use Psl\URL\URL;
use Stringable;

/**
 * Represents a connection origin (scheme + host + port).
 *
 * The origin identifies the target server for a connection. Two requests share
 * a connection pool entry only if their origin, SNI host, and transport settings match. The origin
 * is derived from the request URL's scheme, host, and port (with default ports
 * applied per the scheme).
 *
 * @api
 */
final readonly class Origin implements Stringable
{
    /**
     * The hostname to use for TLS SNI (Server Name Indication).
     *
     * When null, {@see $host} is used. When set, this value is sent as the
     * SNI hostname during the TLS handshake, while {@see $host} is used for
     * the TCP connection target. This is set by DNS-resolving connectors that
     * replace the hostname with a resolved IP address but need to preserve
     * the original hostname for TLS certificate validation.
     *
     * @var null|non-empty-string
     */
    public null|string $sniHost;

    /**
     * @param non-empty-string $scheme The URL scheme (e.g., "https", "http").
     * @param non-empty-string $host The hostname or IP address to connect to.
     * @param int<1, 65535> $port The port number (default port applied if omitted from the URL).
     * @param null|non-empty-string $sniHost The hostname for TLS SNI. Null means use $host.
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        null|string $sniHost = null,
    ) {
        $this->sniHost = $sniHost;
    }

    /**
     * Derive the origin from a parsed URL, applying the default port for the scheme.
     *
     * @param URL $url The parsed URL.
     *
     * @return self The origin for connection pooling.
     */
    public static function fromUrl(URL $url): self
    {
        /** @var int<1, 65535> $port */
        $port = $url->authority->port ?? Internal\default_port($url->scheme) ?? 80;

        return new self($url->scheme, $url->authority->host->toString(), $port);
    }

    /**
     * Return the origin as "scheme://host:port".
     */
    public function toString(): string
    {
        return $this->scheme . '://' . $this->host . ':' . $this->port;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
