<?php

declare(strict_types=1);

namespace Psl\CIDR;

use Psl\IP\Address;

use function chr;
use function count;
use function ctype_digit;
use function explode;
use function inet_pton;
use function str_pad;
use function str_repeat;
use function strlen;

/**
 * Represents a CIDR (Classless Inter-Domain Routing) block.
 *
 * Supports IPv4 and IPv6 addresses, with containment restricted to the same family.
 * Host bits in the supplied address are masked to form the network address.
 *
 * Usage:
 *   $block = new Block('192.168.1.0/24');
 *   $block->contains('192.168.1.100'); // true
 *   $block->contains('192.168.2.1');   // false
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class Block
{
    private string $networkBytes;
    private string $maskBytes;

    /**
     * @param non-empty-string $cidr CIDR notation, e.g. "192.168.1.0/24" or "2001:db8::/32".
     *
     * @throws Exception\InvalidArgumentException If the CIDR notation is invalid.
     */
    public function __construct(string $cidr)
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            throw new Exception\InvalidArgumentException(
                "Invalid CIDR notation: '{$cidr}'. Expected format: 'address/prefix'.",
            );
        }

        [$address, $prefixStr] = $parts;

        if (!ctype_digit($prefixStr)) {
            throw new Exception\InvalidArgumentException(
                "Invalid prefix length: '{$prefixStr}'. Must be a non-negative integer.",
            );
        }

        $networkBytes = inet_pton($address);
        if ($networkBytes === false) {
            throw new Exception\InvalidArgumentException("Invalid IP address in CIDR notation: '{$address}'.");
        }

        $prefix = (int) $prefixStr;
        $length = strlen($networkBytes);
        if ($prefix < 0 || $prefix > ($length * 8)) {
            throw new Exception\InvalidArgumentException(
                "Invalid prefix length: {$prefixStr}. Must be 0-32 for IPv4 or 0-128 for IPv6.",
            );
        }

        /**
         * Build the bitmask
         *
         * @var non-negative-int $fullBytes
         */
        $fullBytes = (int) ($prefix / 8);
        $remainingBits = $prefix % 8;

        $mask = str_repeat("\xff", $fullBytes);
        if ($remainingBits > 0) {
            $mask .= chr((0xFF << (8 - $remainingBits)) & 0xFF);
        }

        $mask = str_pad($mask, $length, "\x00");

        $this->networkBytes = $networkBytes & $mask;
        $this->maskBytes = $mask;
    }

    /**
     * Check whether the given IP address falls within this CIDR block and has the same address family.
     *
     * @param non-empty-string|Address $ip IPv4 or IPv6 address to check.
     */
    public function contains(string|Address $ip): bool
    {
        if ($ip instanceof Address) {
            $ipBytes = $ip->toBytes();
        } else {
            $ipBytes = inet_pton($ip);
            if ($ipBytes === false) {
                return false;
            }
        }

        return (
            strlen($ipBytes) === strlen($this->networkBytes)
            && ($ipBytes & $this->maskBytes) === $this->networkBytes
        );
    }
}
