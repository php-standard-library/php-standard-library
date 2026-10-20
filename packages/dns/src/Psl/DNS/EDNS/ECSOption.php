<?php

declare(strict_types=1);

namespace Psl\DNS\EDNS;

use OutOfRangeException;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\IP\Address;
use Throwable;

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
     * @param int<0, max> $sourcePrefixLength Number of significant bits in the address (e.g. 24).
     * @param int<0, max> $scopePrefixLength Scope prefix length; 0 in queries, set by server in responses.
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
        try {
            $binary = $this->address->toBytes();
            $addressBits = strlen($binary) * 8;
            if ($this->sourcePrefixLength > $addressBits) {
                throw new OutOfRangeException(
                    'source prefix length '
                    . $this->sourcePrefixLength
                    . ' exceeds the '
                    . $addressBits
                    . '-bit address',
                );
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
        } catch (Throwable $e) {
            throw InvalidArgumentException::forOptionEncodingFailure('ECS', $e->getMessage(), $e);
        }
    }
}
