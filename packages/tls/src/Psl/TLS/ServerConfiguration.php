<?php

declare(strict_types=1);

namespace Psl\TLS;

/**
 * TLS configuration for servers.
 *
 * Used with {@see Acceptor} and {@see LazyAcceptor}.
 *
 * SNI certificates are served by PHP's own SNI support: a client that asks for one of their
 * host names, or for a name a `*.` wildcard key matches, receives that certificate, and every
 * other client receives the main certificate. Two limits of that support apply. PHP cannot
 * load an SNI certificate whose private key is protected by a passphrase, so a handshake with
 * such a configuration fails with {@see Exception\HandshakeFailedException} before it starts;
 * and PHP negotiates no ALPN protocol for a connection served by an SNI certificate. Where
 * either matters, inspect the requested name with {@see LazyAcceptor} and complete the
 * handshake with a configuration whose main certificate is the one that name needs.
 *
 * @psalm-immutable
 *
 * @api
 */
final readonly class ServerConfiguration
{
    /**
     * @param Certificate $certificate Server certificate (required for TLS servers).
     * @param ?Version $minimumVersion Minimum TLS protocol version to accept.
     * @param ?Version $maximumVersion Maximum TLS protocol version to accept.
     * @param ?non-empty-string $ciphers OpenSSL cipher string to use.
     * @param int<0, 5> $securityLevel OpenSSL security level.
     * @param ?non-empty-string $certificateAuthority Path to a CA file for client certificate verification.
     * @param ?non-empty-string $certificateAuthorityPath Path to a directory of CA files for client certificate verification.
     * @param ?list<non-empty-string> $alpnProtocols ALPN protocol list (e.g. ['h2', 'http/1.1']).
     * @param array<non-empty-string, Certificate> $sniCertificates SNI hostname-to-certificate mapping.
     * @param bool $sessionTickets Whether to enable TLS session tickets for session resumption.
     *
     * @psalm-mutation-free
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        public Certificate $certificate,
        public null|Version $minimumVersion = null,
        public null|Version $maximumVersion = null,
        public null|string $ciphers = null,
        public int $securityLevel = 2,
        public null|string $certificateAuthority = null,
        public null|string $certificateAuthorityPath = null,
        /** @var null|list<non-empty-string> */
        public null|array $alpnProtocols = null,
        public array $sniCertificates = [],
        public bool $sessionTickets = true,
    ) {}

    /**
     * @pure
     */
    public static function create(Certificate $certificate): self
    {
        return new self($certificate);
    }

    /**
     * @psalm-mutation-free
     */
    public function withCertificate(Certificate $certificate): self
    {
        return new self(
            $certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @psalm-mutation-free
     */
    public function withMinimumVersion(null|Version $minimumVersion): self
    {
        return new self(
            $this->certificate,
            $minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @psalm-mutation-free
     */
    public function withMaximumVersion(null|Version $maximumVersion): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @param ?non-empty-string $ciphers OpenSSL cipher string.
     *
     * @psalm-mutation-free
     */
    public function withCiphers(null|string $ciphers): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @param int<0, 5> $securityLevel OpenSSL security level.
     *
     * @psalm-mutation-free
     */
    public function withSecurityLevel(int $securityLevel): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @param ?non-empty-string $certificateAuthority Path to a CA file.
     *
     * @psalm-mutation-free
     */
    public function withCertificateAuthority(null|string $certificateAuthority): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @param ?non-empty-string $certificateAuthorityPath Path to a CA directory.
     *
     * @psalm-mutation-free
     */
    public function withCertificateAuthorityPath(null|string $certificateAuthorityPath): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @param ?list<non-empty-string> $alpnProtocols ALPN protocol list (e.g. ['h2', 'http/1.1']).
     *
     * @psalm-mutation-free
     */
    public function withAlpnProtocols(null|array $alpnProtocols): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $alpnProtocols,
            $this->sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @param non-empty-string $hostname The SNI hostname.
     * @param Certificate $certificate The certificate to use for this hostname.
     *
     * @psalm-mutation-free
     */
    public function withSniCertificate(string $hostname, Certificate $certificate): self
    {
        $sni = $this->sniCertificates;
        $sni[$hostname] = $certificate;

        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $sni,
            $this->sessionTickets,
        );
    }

    /**
     * @param array<non-empty-string, Certificate> $sniCertificates SNI hostname-to-certificate mapping.
     *
     * @psalm-mutation-free
     */
    public function withSniCertificates(array $sniCertificates): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $sniCertificates,
            $this->sessionTickets,
        );
    }

    /**
     * @psalm-mutation-free
     */
    public function withSessionTickets(bool $enabled): self
    {
        return new self(
            $this->certificate,
            $this->minimumVersion,
            $this->maximumVersion,
            $this->ciphers,
            $this->securityLevel,
            $this->certificateAuthority,
            $this->certificateAuthorityPath,
            $this->alpnProtocols,
            $this->sniCertificates,
            $enabled,
        );
    }
}
