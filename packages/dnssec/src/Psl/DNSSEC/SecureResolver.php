<?php

declare(strict_types=1);

namespace Psl\DNSSEC;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Crypto\Exception\InvalidArgumentException as CryptoInvalidArgumentException;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Exception\RuntimeException;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverConvenienceMethodsTrait;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\BrokenTrustChainException;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Exception\UnsignedResponseException;
use Psl\DNSSEC\Internal\AnswerRrsets;
use Psl\DNSSEC\Internal\DNSSECVerification;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\NSEC\NSECProofValidator;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\Exception\LogicException;

use function array_filter;
use function array_values;
use function count;
use function rtrim;
use function strpos;
use function strtolower;
use function substr;

/**
 * A DNSSEC-validating resolver that wraps an inner resolver and verifies
 * response signatures against validated DNSKEY records obtained from a
 * {@see TrustChainResolverInterface}.
 *
 * For secure zones, every response is verified via RRSIG signatures. For
 * insecure zones (proven unsigned via DS non-existence), responses are
 * returned with the authenticated data flag cleared. Negative responses
 * (NXDOMAIN, NODATA) are validated using NSEC or NSEC3 proofs.
 *
 * @api
 */
final readonly class SecureResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * @param ResolverInterface $resolver The inner DNS resolver to perform queries.
     * @param TrustChainResolverInterface $trustChainResolver The resolver used to obtain validated DNSKEY records.
     */
    public function __construct(
        private ResolverInterface $resolver,
        private TrustChainResolverInterface $trustChainResolver,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException If a DNS query fails due to a transport error.
     * @throws InvalidArgumentException If the query name or options are invalid.
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     * @throws BrokenTrustChainException If the DNSSEC chain of trust validation fails for the zone.
     * @throws InvalidProofException If required RRSIG, NSEC, or NSEC3 proof records are missing or invalid.
     * @throws UnsignedResponseException If a signed response is expected but the response is unsigned.
     * @throws SignatureFailedException If an RRSIG signature fails cryptographic verification.
     */
    public function query(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        array $ednsOptions = [],
    ): Response {
        $response = $this->resolver->query($name, $type, $cancellation, $ednsOptions);

        if ($response->answers === []) {
            return $this->validateNegativeResponse($response, $name, $type, $cancellation);
        }

        return $this->validateResponse($response, $name, $type, $cancellation);
    }

    /**
     * Validate the DNSSEC signatures on a positive response.
     *
     * @throws RuntimeException If a DNS query fails due to a transport or encoding error.
     * @throws InvalidArgumentException If the query name or options are invalid.
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     * @throws BrokenTrustChainException If the chain of trust validation fails.
     * @throws InvalidProofException If RRSIG records are missing from a signed zone.
     * @throws SignatureFailedException If an RRSIG signature fails verification.
     */
    private function validateResponse(
        Response $response,
        string $queryName,
        RecordType $queryType,
        CancellationTokenInterface $cancellation,
    ): Response {
        $rrsigs = DNSSECVerification::filterRecords($response->answers, RecordType::RRSIG);
        if (count($rrsigs) > 8) {
            throw InvalidProofException::forExcessiveRRSIGCount(count($rrsigs));
        }

        [$rrsets, $synthesized] = AnswerRrsets::select($response->answers, $queryName, $queryType);
        $secure = true;
        /** @var array<string, TrustChainResult> $results */
        $results = [];
        foreach ($rrsets as $id => $rrset) {
            if (isset($synthesized[$id])) {
                continue;
            }

            $record = $rrset[0];
            $signatures = array_values(array_filter(
                $rrsigs,
                static fn(RecordInterface $r): bool => (
                    $r instanceof RRSIGRecord
                    && AnswerRrsets::id($r->name, $r->typeCovered) === $id
                ),
            ));
            foreach ($signatures as $signature) {
                if (
                    $signature instanceof RRSIGRecord
                    && !RRSIGVerifier::isSignerForName($signature->signer, $record->name)
                ) {
                    throw SignatureFailedException::forRRSIG($record->kind->name);
                }
            }

            // DS records belong to the parent zone, even at a signed delegation.
            $owner = self::normalizeName($record->name);
            $zone = $owner;
            if ($record->kind === RecordType::DS) {
                $dot = strpos($owner, '.');
                $zone = $dot === false ? '.' : substr($owner, $dot + 1);
            }

            $results[$zone] ??= $this->trustChainResolver->resolve($zone === '' ? '.' : $zone, $cancellation);
            $result = $results[$zone];
            if ($result->status === TrustChainStatus::Bogus) {
                throw BrokenTrustChainException::forValidationFailure($result->failure->name ?? 'unknown');
            }

            if ($result->status === TrustChainStatus::Insecure) {
                $secure = false;
                continue;
            }

            if ($signatures === []) {
                throw InvalidProofException::forRRSIG($record->name);
            }

            if (count($result->keys) > 8) {
                throw InvalidProofException::forExcessiveDNSKEYCount(count($result->keys));
            }

            self::verifyRrsigs($rrset, $signatures, $result->keys);
        }

        $answers = array_values(array_filter(
            $response->answers,
            static fn(RecordInterface $r): bool => isset(
                $rrsets[AnswerRrsets::id($r->name, $r instanceof RRSIGRecord ? $r->typeCovered : $r->kind)],
            ),
        ));

        return new Response(
            $response->id,
            $response->code,
            $answers,
            $response->authority,
            $response->additional,
            $response->authoritativeAnswer,
            $response->recursionDesired,
            $response->recursionAvailable,
            $secure && $response->authenticatedData,
            $response->checkingDisabled,
        );
    }

    private static function normalizeName(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }

    /**
     * Validate a negative response (NXDOMAIN or NODATA) using NSEC/NSEC3 proofs.
     *
     * @throws RuntimeException If a DNS query fails due to a transport or encoding error.
     * @throws InvalidArgumentException If the query name or options are invalid.
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     * @throws BrokenTrustChainException If the chain of trust validation fails.
     * @throws InvalidProofException If required proof records are missing.
     * @throws UnsignedResponseException If the negative response or its proofs are unsigned.
     * @throws SignatureFailedException If an RRSIG signature fails verification.
     */
    private function validateNegativeResponse(
        Response $response,
        string $queryName,
        RecordType $queryKind,
        CancellationTokenInterface $cancellation,
    ): Response {
        $nsecPresent =
            DNSSECVerification::filterRecords($response->authority, RecordType::NSEC) !== []
            || DNSSECVerification::filterRecords($response->authority, RecordType::NSEC3) !== [];

        $rrsigs = DNSSECVerification::filterRecords($response->authority, RecordType::RRSIG);

        if (!$nsecPresent) {
            if ($rrsigs !== []) {
                throw InvalidProofException::forSignedResponse($queryName);
            }

            $result = $this->trustChainResolver->resolve($queryName, $cancellation);
            if ($result->status === TrustChainStatus::Insecure) {
                return self::withoutAuthenticatedData($response);
            }

            if ($result->status === TrustChainStatus::Bogus) {
                throw BrokenTrustChainException::forValidationFailure($result->failure->name ?? 'unknown');
            }

            throw UnsignedResponseException::forNegativeResponse($queryName);
        }

        if ($rrsigs === []) {
            throw UnsignedResponseException::forProof($queryName);
        }

        $firstRrsig = $rrsigs[0];
        if ($firstRrsig instanceof RRSIGRecord) {
            if (!RRSIGVerifier::isSignerForName($firstRrsig->signer, $queryName)) {
                throw SignatureFailedException::forRRSIG($firstRrsig->typeCovered->name);
            }

            $result = $this->trustChainResolver->resolve($firstRrsig->signer, $cancellation);
            if ($result->status === TrustChainStatus::Insecure) {
                return self::withoutAuthenticatedData($response);
            }

            if ($result->status === TrustChainStatus::Bogus) {
                throw BrokenTrustChainException::forValidationFailure($result->failure->name ?? 'unknown');
            }

            self::verifyRrsigs($response->authority, $rrsigs, $result->keys);
        }

        if ($response->code === ResponseCode::NonExistentDomain) {
            NSECProofValidator::validateNxdomain($queryName, $response->authority);
        } else {
            NSECProofValidator::validateNodata($queryName, $queryKind, $response->authority);
        }

        return $response;
    }

    /**
     * Create a copy of the response with the authenticated data flag cleared.
     */
    private static function withoutAuthenticatedData(Response $response): Response
    {
        return new Response(
            $response->id,
            $response->code,
            $response->answers,
            $response->authority,
            $response->additional,
            $response->authoritativeAnswer,
            $response->recursionDesired,
            $response->recursionAvailable,
            false,
            $response->checkingDisabled,
        );
    }

    /**
     * Verify RRSIG records over the answer RRset.
     *
     * @param list<RecordInterface> $answers
     * @param list<RecordInterface> $rrsigs
     * @param list<DNSKEYRecord>    $dnskeys
     *
     * @throws SignatureFailedException If RRSIG verification fails for an RRset.
     * @throws InvalidProofException If the RRSIG count exceeds safety limits.
     */
    private static function verifyRrsigs(array $answers, array $rrsigs, array $dnskeys): void
    {
        $rrsigCount = 0;
        foreach ($rrsigs as $r) {
            if (!$r instanceof RRSIGRecord) {
                continue;
            }

            $rrsigCount++;
        }

        if ($rrsigCount > 8) {
            throw InvalidProofException::forExcessiveRRSIGCount($rrsigCount);
        }

        foreach ($rrsigs as $rrsig) {
            if (!$rrsig instanceof RRSIGRecord) {
                continue;
            }

            $rrset = array_values(array_filter(
                $answers,
                static fn(RecordInterface $r): bool => (
                    $r->kind === $rrsig->typeCovered
                    && !$r instanceof RRSIGRecord
                    && self::normalizeName($r->name) === self::normalizeName($rrsig->name)
                ),
            ));

            if ($rrset === []) {
                continue;
            }

            $verified = false;
            foreach ($dnskeys as $dnskey) {
                if (KeyTag::compute($dnskey) !== $rrsig->keyTag || $dnskey->algorithm !== $rrsig->algorithm) {
                    continue;
                }

                try {
                    if (RRSIGVerifier::verify($rrsig, $dnskey, $rrset)) {
                        $verified = true;
                        break;
                    }
                } catch (
                    InvalidArgumentException|SignatureFailedException|CryptoInvalidArgumentException|LogicException
                ) {
                    // @mago-expect lint:no-empty-catch-clause - continue to the next key
                }
            }

            if (!$verified) {
                throw SignatureFailedException::forRRSIG($rrsig->typeCovered->name);
            }
        }
    }
}
