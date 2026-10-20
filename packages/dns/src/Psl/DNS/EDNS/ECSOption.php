<?php

declare(strict_types=1);

namespace Psl\DNS\EDNS;

use Psl\DNS\Exception\InvalidArgumentException;
use Psl\IP\Address;

use function ceil;
use function chr;
use function ord;
use function pack;
use function strlen;
use function substr;

/**
 * EDNS Client Subnet option (RFC 7871, option code 8).
 *
 * Carries client subnet information to allow authoritative servers to
 * return geographically appropriate responses.
 *
 * @api
 */
final class ECSOption implements OptionInterface
{
    /**
     * {@inheritDoc}
     */
    public int $code {
        get => 8;
    }

    /**
     * @param Address $address The client subnet address.
     * @param int $sourcePrefixLength Number of significant bits in the address (e.g. 24).
     * @param int $scopePrefixLength Scope prefix length; 0 in queries, set by server in responses.
     */
    public function __construct(
        public readonly Address $address,
        public readonly int $sourcePrefixLength,
        public readonly int $scopePrefixLength,
    ) {}

    /**
     * @throws InvalidArgumentException If the ECS option cannot be encoded.
     */
    public function toWireFormat(): string
    {
        $binary = $this->address->toBytes();
        $addressBits = strlen($binary) * 8;
        foreach (['source' => $this->sourcePrefixLength, 'scope' => $this->scopePrefixLength] as $field => $length) {
            if ($length < 0 || $length > $addressBits) {
                throw InvalidArgumentException::forOptionEncodingFailure(
                    'ECS',
                    $field . ' prefix length ' . $length . ' must be between 0 and ' . $addressBits,
                );
            }
        }

        /** @var non-negative-int $truncatedLength */
        $truncatedLength = (int) ceil($this->sourcePrefixLength / 8.0);
        $truncated = substr($binary, 0, $truncatedLength);

        $bitsInLastByte = $this->sourcePrefixLength % 8;
        if ($bitsInLastByte > 0 && $truncatedLength > 0) {
            $lastIndex = $truncatedLength - 1;
            $mask = 0xFF << (8 - $bitsInLastByte);
            $truncated = substr($truncated, 0, $lastIndex) . chr(ord($truncated[$lastIndex]) & $mask);
        }

        return (
            pack('nCC', $this->address->family->ianaFamily(), $this->sourcePrefixLength, $this->scopePrefixLength)
            . $truncated
        );
    }
}
