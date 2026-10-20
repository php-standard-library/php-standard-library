<?php

declare(strict_types=1);

namespace Psl\MIME\DKIM;

/**
 * Immutable configuration for DKIM message signing.
 *
 * Encapsulates all parameters that control how a DKIM-Signature header is constructed,
 * including the signing domain, selector, algorithm, canonicalization modes, and optional
 * constraints such as signature expiration and body length limits.
 *
 * Use the fluent `with*()` methods to derive modified copies of this configuration.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc6376 RFC 6376 - DomainKeys Identified Mail (DKIM) Signatures
 * @link https://datatracker.ietf.org/doc/html/rfc8301 RFC 8301 - Cryptographic Algorithm and Key Usage Update
 *
 * @see Signer For the class that consumes this configuration.
 *
 * @api
 */
final readonly class SigningConfiguration
{
    /**
     * Create a new DKIM signing configuration.
     *
     * @param non-empty-string $domain The signing domain identity, recorded in the "d=" tag.
     * @param non-empty-string $selector The DNS selector, recorded in the "s=" tag. The verifier
     *                                   retrieves the public key from "{selector}._domainkey.{domain}".
     * @param Algorithm $algorithm The signing algorithm recorded in the "a=" tag.
     * @param Canonicalization $headerCanonicalization Canonicalization mode applied to headers before signing.
     * @param Canonicalization $bodyCanonicalization Canonicalization mode applied to the message body before signing.
     * @param int<0, max> $signatureExpirationDelay Number of seconds after signing until the signature
     *                                              expires (recorded in the "x=" tag). 0 means no expiry.
     * @param int<0, max> $bodyMaxLength Maximum number of body bytes to include in the hash. 0 means
     *                                   no limit. When the body is truncated, the "l=" tag is always
     *                                   included, since verifiers would otherwise hash the whole body.
     * @param bool $includeBodyLength Whether to include the "l=" (body length) tag in the signature
     *                                even when the body is not truncated.
     * @param list<non-empty-lowercase-string> $headersToIgnore Lowercase header names to exclude from
     *                                                          signing. The "From" header is always signed
     *                                                          regardless of this list.
     */
    public function __construct(
        public string $domain,
        public string $selector,
        public Algorithm $algorithm = Algorithm::RsaSha256,
        public Canonicalization $headerCanonicalization = Canonicalization::Relaxed,
        public Canonicalization $bodyCanonicalization = Canonicalization::Relaxed,
        public int $signatureExpirationDelay = 0,
        public int $bodyMaxLength = 0,
        public bool $includeBodyLength = false,
        public array $headersToIgnore = [],
    ) {}

    /**
     * Return a new configuration with the given signing algorithm.
     */
    public function withAlgorithm(Algorithm $algorithm): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $algorithm,
            $this->headerCanonicalization,
            $this->bodyCanonicalization,
            $this->signatureExpirationDelay,
            $this->bodyMaxLength,
            $this->includeBodyLength,
            $this->headersToIgnore,
        );
    }

    /**
     * Return a new configuration with the given header canonicalization mode.
     */
    public function withHeaderCanonicalization(Canonicalization $canonicalization): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $this->algorithm,
            $canonicalization,
            $this->bodyCanonicalization,
            $this->signatureExpirationDelay,
            $this->bodyMaxLength,
            $this->includeBodyLength,
            $this->headersToIgnore,
        );
    }

    /**
     * Return a new configuration with the given body canonicalization mode.
     */
    public function withBodyCanonicalization(Canonicalization $canonicalization): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $this->algorithm,
            $this->headerCanonicalization,
            $canonicalization,
            $this->signatureExpirationDelay,
            $this->bodyMaxLength,
            $this->includeBodyLength,
            $this->headersToIgnore,
        );
    }

    /**
     * Return a new configuration with the given signature expiration delay.
     *
     * @param int<0, max> $seconds Number of seconds after signing until expiry. 0 disables expiration.
     */
    public function withSignatureExpirationDelay(int $seconds): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $this->algorithm,
            $this->headerCanonicalization,
            $this->bodyCanonicalization,
            $seconds,
            $this->bodyMaxLength,
            $this->includeBodyLength,
            $this->headersToIgnore,
        );
    }

    /**
     * Return a new configuration with the given maximum body length for hashing.
     *
     * When a body is longer than this, the signature covers only its first $maxLength bytes and
     * carries an "l=" tag, even without {@see withIncludeBodyLength()}.
     *
     * @param int<0, max> $maxLength Maximum body bytes to hash. 0 means no limit.
     */
    public function withBodyMaxLength(int $maxLength): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $this->algorithm,
            $this->headerCanonicalization,
            $this->bodyCanonicalization,
            $this->signatureExpirationDelay,
            $maxLength,
            $this->includeBodyLength,
            $this->headersToIgnore,
        );
    }

    /**
     * Return a new configuration that includes or excludes the "l=" (body length) tag.
     *
     * The tag is always included when the body is truncated to the maximum body length.
     */
    public function withIncludeBodyLength(bool $include = true): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $this->algorithm,
            $this->headerCanonicalization,
            $this->bodyCanonicalization,
            $this->signatureExpirationDelay,
            $this->bodyMaxLength,
            $include,
            $this->headersToIgnore,
        );
    }

    /**
     * Return a new configuration with the given list of headers to exclude from signing.
     *
     * @param list<non-empty-lowercase-string> $headers Lowercase header names to skip. The "From"
     *                                                  header is always signed regardless.
     */
    public function withHeadersToIgnore(array $headers): self
    {
        return new self(
            $this->domain,
            $this->selector,
            $this->algorithm,
            $this->headerCanonicalization,
            $this->bodyCanonicalization,
            $this->signatureExpirationDelay,
            $this->bodyMaxLength,
            $this->includeBodyLength,
            $headers,
        );
    }
}
