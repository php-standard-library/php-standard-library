<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Exception;

/**
 * Thrown when a DNSSEC signature verification fails or when cryptographic key/signature
 * data is malformed or has an unsupported format.
 *
 * @api
 */
final class SignatureFailedException extends RuntimeException
{
    /**
     * The record type name (e.g., "A", "AAAA") that the RRSIG was covering, if applicable.
     */
    public readonly null|string $typeCovered;

    private function __construct(string $message, null|string $typeCovered = null)
    {
        $this->typeCovered = $typeCovered;
        parent::__construct($message);
    }

    /**
     * Create an exception when RRSIG verification fails for a given record type.
     */
    public static function forRRSIG(string $typeCovered): self
    {
        return new self('RRSIG verification failed for ' . $typeCovered . ' records.', $typeCovered);
    }

    /**
     * Create an exception when an RSA public key is too short for DER encoding.
     */
    public static function forRSAKeyTooShort(): self
    {
        return new self('RSA public key is too short for DER encoding.');
    }

    /**
     * Create an exception when the RSA exponent length overflows available key data.
     */
    public static function forRSAExponentOverflow(): self
    {
        return new self('RSA exponent length exceeds available key data.');
    }

    /**
     * Create an exception when an RSA public key has an empty exponent or modulus.
     */
    public static function forEmptyRSAKeyComponent(): self
    {
        return new self('RSA public key must have a non-empty exponent and modulus.');
    }

    /**
     * Create an exception for an unsupported ECDSA coordinate size.
     */
    public static function forUnsupportedCoordinateSize(int $size): self
    {
        return new self('Unsupported ECDSA coordinate size: ' . $size . '.');
    }

    /**
     * Create an exception when an ECDSA signature is shorter than expected.
     */
    public static function forECDSASignatureTooShort(int $expected): self
    {
        return new self('ECDSA signature too short, expected ' . $expected . ' bytes.');
    }
}
