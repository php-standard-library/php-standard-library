<?php

declare(strict_types=1);

namespace Psl\IP;

use Psl\Comparison\Comparable;
use Psl\Comparison\Equable;
use Psl\Comparison\Exception\IncomparableException;
use Psl\Comparison\Order;
use Psl\IP\Exception\InvalidArgumentException;
use Stringable;

use function array_reverse;
use function dechex;
use function explode;
use function filter_var;
use function implode;
use function inet_ntop;
use function inet_pton;
use function ord;
use function str_pad;
use function str_replace;
use function str_split;
use function strcmp;
use function strlen;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use const STR_PAD_LEFT;

/**
 * Immutable IP address value object backed by raw binary bytes.
 *
 * @implements Comparable<Address>
 * @implements Equable<Address>
 *
 * @api
 */
final readonly class Address implements Stringable, Comparable, Equable
{
    /**
     * @param non-empty-string $bytes Raw binary bytes (4 for IPv4, 16 for IPv6).
     */
    private function __construct(
        public Family $family,
        private string $bytes,
    ) {}

    /**
     * Parse an IPv4 address from its human-readable dotted-decimal form.
     *
     * @param non-empty-string $address
     *
     * @throws InvalidArgumentException If the address is not a valid IPv4 address.
     */
    public static function v4(string $address): self
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException(
                'Expected a valid IPv4 address in dotted-decimal notation (e.g., "192.168.1.1"), got "'
                . $address
                . '".',
            );
        }

        /** @var non-empty-string $bytes */
        $bytes = inet_pton($address);

        return new self(Family::V4, $bytes);
    }

    /**
     * Parse an IPv6 address from its human-readable colon-hex form.
     *
     * @param non-empty-string $address
     *
     * @throws InvalidArgumentException If the address is not a valid IPv6 address.
     */
    public static function v6(string $address): self
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException(
                'Expected a valid IPv6 address (e.g., "2001:db8::1"), got "' . $address . '".',
            );
        }

        /** @var non-empty-string $bytes */
        $bytes = inet_pton($address);

        return new self(Family::V6, $bytes);
    }

    /**
     * Create from raw binary bytes (4 for IPv4, 16 for IPv6).
     *
     * @param non-empty-string $bytes
     *
     * @throws InvalidArgumentException If the byte length is not 4 or 16.
     */
    public static function fromBytes(string $bytes): self
    {
        $length = strlen($bytes);

        return match ($length) {
            4 => new self(Family::V4, $bytes),
            16 => new self(Family::V6, $bytes),
            default => throw new InvalidArgumentException(
                'Expected 4 bytes for an IPv4 address or 16 bytes for an IPv6 address, got ' . $length . '.',
            ),
        };
    }

    /**
     * Parse an IP address string, auto-detecting v4 or v6.
     *
     * @param non-empty-string $address
     *
     * @throws InvalidArgumentException If the address is not a valid IP address.
     */
    public static function parse(string $address): self
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::v4($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::v6($address);
        }

        throw new InvalidArgumentException('Expected a valid IPv4 or IPv6 address, got "' . $address . '".');
    }

    /**
     * Compare two addresses by their raw bytes (in network byte order), so addresses of the same
     * family are ordered numerically.
     *
     * @throws IncomparableException If the other value is not an Address instance.
     */
    public function compare(mixed $other): Order
    {
        // @mago-expect analysis:impossible-condition - runtime check.
        if (!$other instanceof self) {
            // @mago-expect analysis:no-value - runtime check.
            throw IncomparableException::fromValues($this, $other);
        }

        // `<=>` would compare the byte strings numerically when both happen to be numeric strings
        // (e.g. "0012" and "  12", the bytes of 48.48.49.50 and 32.32.49.50), so compare the bytes.
        return Order::from(strcmp($this->bytes, $other->bytes) <=> 0);
    }

    /**
     * Check whether two addresses are equal by their raw bytes.
     */
    public function equals(mixed $other): bool
    {
        // @mago-expect analysis:impossible-condition - runtime check.
        if (!$other instanceof self) {
            return false;
        }

        return $this->bytes === $other->bytes;
    }

    /**
     * Get the reverse DNS name for this address.
     *
     * IPv4: "192.168.1.10" → "10.1.168.192.in-addr.arpa"
     * IPv6: "2001:db8::1" → "1.0.0.0...8.b.d.0.1.0.0.2.ip6.arpa"
     */
    public function toArpaName(): string
    {
        if ($this->family === Family::V4) {
            $parts = explode('.', $this->toString());

            return implode('.', array_reverse($parts)) . '.in-addr.arpa';
        }

        $hex = str_replace(':', '', $this->toExpandedString());
        $chars = str_split($hex);

        return implode('.', array_reverse($chars)) . '.ip6.arpa';
    }

    /**
     * Whether this is a loopback address.
     *
     * IPv4: 127.0.0.0/8
     * IPv6: ::1
     */
    public function isLoopback(): bool
    {
        if ($this->family === Family::V4) {
            return $this->bytes[0] === "\x7f";
        }

        return $this->bytes === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01";
    }

    /**
     * Whether this is a private/local address.
     *
     * IPv4: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
     * IPv6: fc00::/7 (unique local)
     */
    public function isPrivate(): bool
    {
        if ($this->family === Family::V4) {
            return (
                $this->bytes[0] === "\x0a"
                || $this->bytes[0] === "\xac"
                && (ord($this->bytes[1]) & 0xf0) === 0x10
                || $this->bytes[0] === "\xc0"
                && $this->bytes[1] === "\xa8"
            );
        }

        return (ord($this->bytes[0]) & 0xfe) === 0xfc;
    }

    /**
     * Whether this is a link-local address.
     *
     * IPv4: 169.254.0.0/16
     * IPv6: fe80::/10
     */
    public function isLinkLocal(): bool
    {
        if ($this->family === Family::V4) {
            return $this->bytes[0] === "\xa9" && $this->bytes[1] === "\xfe";
        }

        return (ord($this->bytes[0]) & 0xff) === 0xfe && (ord($this->bytes[1]) & 0xc0) === 0x80;
    }

    /**
     * Whether this is a multicast address.
     *
     * IPv4: 224.0.0.0/4
     * IPv6: ff00::/8
     */
    public function isMulticast(): bool
    {
        if ($this->family === Family::V4) {
            return (ord($this->bytes[0]) & 0xf0) === 0xe0;
        }

        return $this->bytes[0] === "\xff";
    }

    /**
     * Whether this is the unspecified (all-zeros) address.
     *
     * IPv4: 0.0.0.0
     * IPv6: ::
     */
    public function isUnspecified(): bool
    {
        if ($this->family === Family::V4) {
            return $this->bytes === "\x00\x00\x00\x00";
        }

        return $this->bytes === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    }

    /**
     * Whether this is a globally routable unicast address.
     *
     * True when not loopback, private, link-local, multicast,
     * unspecified, or documentation.
     */
    public function isGlobalUnicast(): bool
    {
        return !$this->isLoopback()
        && !$this->isPrivate()
        && !$this->isLinkLocal()
        && !$this->isMulticast()
        && !$this->isUnspecified()
        && !$this->isDocumentation();
    }

    /**
     * Whether this is a documentation/example address.
     *
     * IPv4: 192.0.2.0/24 (TEST-NET-1), 198.51.100.0/24 (TEST-NET-2), 203.0.113.0/24 (TEST-NET-3)
     * IPv6: 2001:db8::/32
     */
    public function isDocumentation(): bool
    {
        if ($this->family === Family::V4) {
            return (
                $this->bytes[0] === "\xc0"
                && $this->bytes[1] === "\x00"
                && $this->bytes[2] === "\x02"
                || $this->bytes[0] === "\xc6"
                && $this->bytes[1] === "\x33"
                && $this->bytes[2] === "\x64"
                || $this->bytes[0] === "\xcb"
                && $this->bytes[1] === "\x00"
                && $this->bytes[2] === "\x71"
            );
        }

        return (
            $this->bytes[0] === "\x20"
            && $this->bytes[1] === "\x01"
            && $this->bytes[2] === "\x0d"
            && $this->bytes[3] === "\xb8"
        );
    }

    /**
     * Get the raw binary bytes of the address.
     *
     * @return non-empty-string
     */
    public function toBytes(): string
    {
        return $this->bytes;
    }

    /**
     * Get the human-readable string representation of the address.
     *
     * IPv6 addresses are compressed per RFC 5952.
     *
     * @return non-empty-string
     */
    public function toString(): string
    {
        /** @var non-empty-string */
        return inet_ntop($this->bytes);
    }

    /**
     * Get the fully expanded string representation of the address.
     *
     * For IPv6, all groups are zero-padded to four hex digits with no compression.
     * For IPv4, this is identical to {@see toString()}.
     *
     * @return non-empty-string
     */
    public function toExpandedString(): string
    {
        if ($this->family === Family::V4) {
            return $this->toString();
        }

        $groups = [];
        for ($i = 0; $i < 16; $i += 2) {
            $value = (ord($this->bytes[$i]) << 8) | ord($this->bytes[$i + 1]);
            $groups[] = str_pad(dechex($value), 4, '0', STR_PAD_LEFT);
        }

        /** @var non-empty-string */
        return implode(':', $groups);
    }

    /**
     * @return non-empty-string
     *
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
