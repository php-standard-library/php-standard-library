<?php

declare(strict_types=1);

namespace Psl\DNS\Exception;

use Throwable;

/**
 * Thrown when a DNS response violates the DNS protocol, including malformed packets,
 * compression errors, record data overflows, truncated responses, and response mismatches.
 *
 * @api
 */
final class ProtocolException extends RuntimeException
{
    /**
     * The expected value (transaction ID or source address), if applicable.
     */
    public readonly null|string|int $expected;

    /**
     * The actual value received, if applicable.
     */
    public readonly null|string|int $actual;

    private function __construct(
        string $message,
        null|string|int $expected = null,
        null|string|int $actual = null,
        null|Throwable $previous = null,
    ) {
        $this->expected = $expected;
        $this->actual = $actual;
        parent::__construct($message, $previous);
    }

    /**
     * Create an exception for a DNS response that could not be decoded.
     */
    public static function forDecodingFailure(string $detail, Throwable $previous): self
    {
        return new self('Failed to decode DNS response: ' . $detail . '.', previous: $previous);
    }

    /**
     * Create an exception when a DNS query packet was received instead of a response.
     */
    public static function forQueryPacket(): self
    {
        return new self('Received a DNS query packet instead of a response.');
    }

    /**
     * Create an exception when a DNS response is shorter than the 12-byte header.
     */
    public static function forTooShort(int $length): self
    {
        return new self('DNS response too short: expected at least 12 bytes, got ' . $length . '.');
    }

    /**
     * Create an exception for an unrecognized DNS response code.
     */
    public static function forUnknownResponseCode(int $code): self
    {
        return new self('DNS response contains unknown response code ' . $code . '.');
    }

    /**
     * Create an exception for an unknown ECS address family in a DNS response.
     */
    public static function forUnknownECSAddressFamily(): self
    {
        return new self('DNS response contains unknown ECS address family.');
    }

    /**
     * Create an exception when the response transaction ID does not match the query.
     */
    public static function forTransactionIDMismatch(int $expected, int $actual): self
    {
        return new self(
            'DNS response transaction ID ' . $actual . ' does not match query ID ' . $expected . '.',
            expected: $expected,
            actual: $actual,
        );
    }

    /**
     * Create an exception when the response question does not match the query.
     */
    public static function forQuestionMismatch(): self
    {
        return new self('DNS response question does not match the query name, type, and class.');
    }

    /**
     * Create an exception when a DNS response arrives from an unexpected source address.
     */
    public static function forUnexpectedSource(string $expected, string $actual): self
    {
        return new self(
            'DNS response received from ' . $actual . ', expected ' . $expected . '.',
            expected: $expected,
            actual: $actual,
        );
    }

    /**
     * Create an exception when a DNS compression pointer loop is detected.
     */
    public static function forPointerLoop(): self
    {
        return new self('DNS compression pointer loop detected.');
    }

    /**
     * Create an exception for a forward-pointing DNS compression pointer.
     */
    public static function forForwardPointer(int $offset): self
    {
        return new self('DNS forward compression pointer at offset ' . $offset . '.');
    }

    /**
     * Create an exception when a DNS label exceeds the 63-octet maximum.
     */
    public static function forLabelTooLong(int $length): self
    {
        return new self('DNS label length ' . $length . ' exceeds maximum of 63.');
    }

    /**
     * Create an exception when a compressed DNS name exceeds the 253-character limit.
     */
    public static function forCompressionNameTooLong(): self
    {
        return new self('DNS name length exceeds maximum of 253 characters.');
    }

    /**
     * Create an exception when the consumed record data does not match RDLENGTH.
     */
    public static function forRDLengthMismatch(int $consumed, int $expected): self
    {
        return new self('DNS record data consumed ' . $consumed . ' bytes, but RDLENGTH specified ' . $expected . '.');
    }

    /**
     * Create an exception when RRSIG record data is too short.
     */
    public static function forRRSIGTooShort(int $length): self
    {
        return new self('RRSIG record data too short: ' . $length . ' bytes.');
    }

    /**
     * Create an exception when the RRSIG signer name overflows the record data.
     */
    public static function forRRSIGSignerOverflow(): self
    {
        return new self('RRSIG signer name exceeds record data length.');
    }

    /**
     * Create an exception when the NSEC next domain name overflows the record data.
     */
    public static function forNSECNextDomainOverflow(): self
    {
        return new self('NSEC next domain name exceeds record data length.');
    }

    /**
     * Create an exception when NSEC3 record data overflows.
     */
    public static function forNSEC3DataOverflow(): self
    {
        return new self('NSEC3 record data exceeds record data length.');
    }

    /**
     * Create an exception when an SVCB/HTTPS parameter header is truncated.
     */
    public static function forSVCBParamTruncated(): self
    {
        return new self('SVCB/HTTPS parameter header truncated.');
    }

    /**
     * Create an exception when an SVCB/HTTPS parameter value overflows.
     */
    public static function forSVCBParamOverflow(): self
    {
        return new self('SVCB/HTTPS parameter value length exceeds record data.');
    }

    /**
     * Create an exception when a DNS UDP response has the truncated flag set.
     */
    public static function forUDPResponse(): self
    {
        return new self('DNS UDP response was truncated.');
    }

    /**
     * Create an exception when the DNSKEY record count exceeds safety limits.
     */
    public static function forExcessiveDNSKEYCount(int $count): self
    {
        return new self('DNS response contains ' . $count . ' DNSKEY records, exceeding safety limit.');
    }

    /**
     * Create an exception when the RRSIG record count exceeds safety limits.
     */
    public static function forExcessiveRRSIGCount(int $count): self
    {
        return new self('DNS response contains ' . $count . ' RRSIG records, exceeding safety limit.');
    }

    /**
     * Create an exception when the NSEC3 iteration count exceeds the allowed maximum.
     */
    public static function forExcessiveNSEC3Iterations(int $iterations, int $limit): self
    {
        return new self('NSEC3 iteration count ' . $iterations . ' exceeds maximum of ' . $limit . '.');
    }

    /**
     * Create an exception when the total record count exceeds the allowed limit.
     */
    public static function forExcessiveRecordCount(int $count, int $limit): self
    {
        return new self('DNS response contains ' . $count . ' records, exceeding the limit of ' . $limit . '.');
    }

    /**
     * Create an exception for a record outside the Internet class.
     */
    public static function forUnsupportedClass(int $class): self
    {
        return new self('Unsupported DNS record class: ' . $class . '.', expected: 1, actual: $class);
    }
}
