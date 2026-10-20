<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Middleware;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\CIDR;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionInterface;
use Psl\HTTP\Client\Exception\RuntimeException;
use Psl\HTTP\Client\Handler\HandlerInterface;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Transaction;
use Psl\IP;
use Psl\Network\SocketScheme;

use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Connection-level middleware that blocks requests to denied IP addresses and CIDR ranges.
 *
 * Provides Server-Side Request Forgery (SSRF) protection by inspecting the resolved
 * peer address of an established connection before the HTTP exchange takes place. If
 * the peer IP matches any entry in the denied list, a {@see RuntimeException} is thrown
 * and the exchange is never performed.
 *
 * ## How IP matching works
 *
 * After the connection is established (DNS resolved, TCP connected, TLS handshake
 * complete), this middleware extracts the peer IP address from
 * {@see ConnectionInterface::$metadata} via the peer address. It then checks this
 * address against the denied list, which can contain:
 *
 * - **Individual IP addresses** ({@see IP\Address}): Exact match against the peer IP.
 * - **CIDR blocks** ({@see CIDR\Block}): Range match; the peer IP is checked for
 *   containment within the CIDR range.
 *
 * Both IPv4 and IPv6 addresses are supported, including IPv4-mapped IPv6 addresses
 * (e.g., `::ffff:127.0.0.1`). IPv4 peers match CIDR entries in both their IPv4 and
 * IPv4-mapped IPv6 forms.
 *
 * ## Why connection-level middleware
 *
 * SSRF protection must operate on the resolved IP address, not the hostname from the
 * URL. Hostname-based checks are insufficient because:
 *
 * - DNS rebinding attacks can cause a hostname to resolve to an internal IP.
 * - The server may be behind a load balancer or CDN, making the hostname unreliable.
 * - Multiple A/AAAA records may resolve to different addresses.
 *
 * By checking the peer address after connection establishment, this middleware inspects
 * the actual IP address the connection was made to, which is immune to DNS rebinding.
 *
 * ## Preset: private network ranges
 *
 * The {@see forPrivateNetworkRanges()} factory method returns a preconfigured instance
 * that blocks private, loopback, link-local, unspecified, shared, reserved, multicast,
 * translation, and 6to4 ranges, including IPv4-mapped IPv6 equivalents.
 * Unix sockets have no peer IP and do not undergo these checks.
 *
 * @link https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html OWASP SSRF Prevention
 *
 * @api
 */
final readonly class DeniedDestinationsMiddleware implements MiddlewareInterface
{
    /**
     * Create a new denied destinations middleware with the given deny list.
     *
     * @param list<CIDR\Block|IP\Address> $denied List of IP addresses and CIDR ranges to deny. An empty list disables the check (all destinations are allowed).
     */
    public function __construct(
        private array $denied,
    ) {}

    /**
     * Create a preset that denies private and other special-purpose IP ranges.
     *
     * This covers the most common SSRF attack vectors by blocking connections to:
     *
     * - **IPv4 loopback** (`127.0.0.0/8`): localhost and loopback addresses.
     * - **IPv4 private** (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`): RFC 1918 private networks.
     * - **IPv4 link-local** (`169.254.0.0/16`): APIPA and cloud metadata endpoints (e.g., AWS `169.254.169.254`).
     * - **IPv4 current network** (`0.0.0.0/8`): includes the unspecified address.
     * - **IPv4 shared** (`100.64.0.0/10`): carrier-grade NAT.
     * - **IPv4 special-purpose** (`192.0.0.0/24`, `198.18.0.0/15`): protocol assignments and benchmarking.
     * - **IPv4 multicast and reserved** (`224.0.0.0/4`, `240.0.0.0/4`): includes the limited broadcast address.
     * - **IPv6 loopback** (`::1`): the IPv6 loopback address.
     * - **IPv6 unique local** (`fc00::/7`): RFC 4193 unique local addresses.
     * - **IPv6 link-local** (`fe80::/10`): IPv6 link-local addresses.
     * - **IPv6 unspecified** (`::`): the IPv6 unspecified address.
     * - **IPv6 translation and 6to4** (`64:ff9b::/96`, `2002::/16`): can encode an IPv4 destination.
     * - **IPv6 multicast** (`ff00::/8`).
     * - **IPv4-mapped IPv6**: The IPv6 equivalents of the above IPv4 ranges (e.g., `::ffff:127.0.0.0/104`).
     *
     * Suitable for any application that fetches user-supplied URLs and needs to prevent
     * access to internal network resources.
     */
    public static function forPrivateNetworkRanges(): self
    {
        return new self([
            // IPv4 loopback
            new CIDR\Block('127.0.0.0/8'),
            // IPv4 private
            new CIDR\Block('10.0.0.0/8'),
            new CIDR\Block('172.16.0.0/12'),
            new CIDR\Block('192.168.0.0/16'),
            // IPv4 link-local
            new CIDR\Block('169.254.0.0/16'),
            // IPv4 current network, shared, special-purpose, multicast, and reserved
            new CIDR\Block('0.0.0.0/8'),
            new CIDR\Block('100.64.0.0/10'),
            new CIDR\Block('192.0.0.0/24'),
            new CIDR\Block('198.18.0.0/15'),
            new CIDR\Block('224.0.0.0/4'),
            new CIDR\Block('240.0.0.0/4'),
            // IPv6 loopback
            IP\Address::v6('::1'),
            // IPv6 private (unique local)
            new CIDR\Block('fc00::/7'),
            // IPv6 link-local
            new CIDR\Block('fe80::/10'),
            // IPv6 unspecified
            IP\Address::v6('::'),
            // IPv6 translation, 6to4, and multicast
            new CIDR\Block('64:ff9b::/96'),
            new CIDR\Block('2002::/16'),
            new CIDR\Block('ff00::/8'),
        ]);
    }

    /**
     * Check the connection's peer address against the deny list and delegate to the next handler.
     *
     * Extracts the peer IP address from {@see ConnectionInterface::$metadata} and checks
     * it against every entry in the deny list. The check is performed in order; the first
     * matching entry causes the middleware to throw immediately, before any HTTP exchange
     * takes place. If the deny list is empty or no entry matches, the request is delegated
     * to the next handler in the chain via {@see HandlerInterface::handle()}.
     *
     * For individual {@see IP\Address} entries, an exact equality check is performed.
     * For {@see CIDR\Block} entries, a containment check determines whether the peer
     * IP falls within the CIDR range.
     *
     * When a destination is denied, the thrown {@see RuntimeException} carries a message
     * in the format: "Connection to {ip} is denied by client configuration."
     *
     * @inheritDoc
     *
     * @throws RuntimeException If the peer IP address matches any entry in the deny list.
     */
    #[Override]
    public function process(
        ConnectionInterface $connection,
        Request $request,
        ClientConfiguration $configuration,
        HandlerInterface $handler,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Transaction {
        $peerAddress = $connection->metadata->peerAddress;
        $peerHost = $peerAddress->host;

        if ($this->denied !== [] && $peerAddress->scheme !== SocketScheme::Unix) {
            $host = str_starts_with($peerHost, '[') && str_ends_with($peerHost, ']')
                ? substr($peerHost, 1, -1)
                : $peerHost;
            if ($host === '') {
                throw new RuntimeException('Connection has an empty peer IP address.');
            }

            $address = self::normalize(IP\Address::parse($host));
            $mappedAddress = $address->family === IP\Family::V4
                ? IP\Address::fromBytes("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $address->toBytes())
                : $address;
            foreach ($this->denied as $denied) {
                if ($denied instanceof CIDR\Block) {
                    if ($denied->contains($address) || $denied->contains($mappedAddress)) {
                        throw new RuntimeException(
                            'Connection to ' . $peerHost . ' is denied by client configuration.',
                        );
                    }
                } elseif (self::normalize($denied)->equals($address)) {
                    throw new RuntimeException('Connection to ' . $peerHost . ' is denied by client configuration.');
                }
            }
        }

        return $handler->handle($connection, $request, $configuration, $cancellation);
    }

    private static function normalize(IP\Address $address): IP\Address
    {
        $bytes = $address->toBytes();

        if (!str_starts_with($bytes, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            return $address;
        }

        /** @var non-empty-string $ipv4 */
        $ipv4 = substr($bytes, 12);

        return IP\Address::fromBytes($ipv4);
    }
}
