<?php

declare(strict_types=1);

namespace Psl\DNSSEC;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Crypto\Exception\InvalidArgumentException;
use Psl\DNS\Exception\InvalidArgumentException as DNSInvalidArgumentException;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Exception\RuntimeException;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Internal\DNSSECVerification;
use Psl\DNSSEC\Internal\DS\DSVerifier;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\NSEC\NSECProofValidator;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\Exception\LogicException;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function rtrim;
use function str_ends_with;
use function strtolower;

/**
 * Default trust chain resolver that walks the DNSSEC chain of trust from the
 * root down to the target zone.
 *
 * Starting from the configured trust anchor (IANA root KSK by default), this
 * resolver queries for DS and DNSKEY records at each level of the DNS hierarchy,
 * verifying cryptographic links at every step. The result indicates whether the
 * target zone is Secure, Insecure (provably unsigned), or Bogus (validation failed).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4033
 * @see https://datatracker.ietf.org/doc/html/rfc4034
 * @see https://datatracker.ietf.org/doc/html/rfc4035
 *
 * @api
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class TrustChainResolver implements TrustChainResolverInterface
{
    /**
     * The trust anchor used as the root of trust for chain validation.
     */
    private TrustAnchor $trustAnchor;

    /**
     * @param ResolverInterface $resolver The DNS resolver used to query for DS and DNSKEY records.
     * @param null|TrustAnchor $trustAnchor The trust anchor to use. Defaults to the IANA root KSK.
     */
    public function __construct(
        private ResolverInterface $resolver,
        null|TrustAnchor $trustAnchor = null,
    ) {
        $this->trustAnchor = $trustAnchor ?? TrustAnchor::root();
    }

    /**
     * {@inheritDoc}
     *
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     */
    public function resolve(
        string $zone,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): TrustChainResult {
        return $this->doResolve($zone, $cancellation);
    }

    /**
     * Walk the chain of trust from root to the target zone.
     *
     * @throws RuntimeException If a DNS query fails due to a transport or encoding error.
     * @throws InvalidArgumentException If the query name or options are invalid.
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     */
    private function doResolve(string $zone, CancellationTokenInterface $cancellation): TrustChainResult
    {
        $zone = $zone === '' || $zone === '.' ? '.' : $zone;
        $zones = self::buildZoneChain($zone);

        /** @var array<string, list<DNSKEYRecord>> $validated */
        $validated = [];
        foreach ($zones as $currentZone) {
            $result = $this->validateZoneKeys($currentZone, $validated, $cancellation);
            if ($result !== null) {
                return $result;
            }
        }

        if (!isset($validated[$zone])) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::ChainBroken);
        }

        return new TrustChainResult(TrustChainStatus::Secure, $validated[$zone]);
    }

    /**
     * Build the chain of zones from root to the target zone.
     *
     * @return list<string>
     */
    private static function buildZoneChain(string $zone): array
    {
        $zones = ['.'];
        if ($zone === '.') {
            return $zones;
        }

        $labels = explode('.', $zone);
        $count = count($labels);
        for ($i = $count - 1; $i >= 0; $i--) {
            $slice = array_slice($labels, $i);
            $zones[] = implode('.', $slice);
        }

        return $zones;
    }

    /**
     * Validate and return DNSKEY records for a zone.
     *
     * Returns null on success (populates $validated), or a TrustChainResult
     * (Insecure or Bogus) to short-circuit the chain walk.
     *
     * @param array<string, list<DNSKEYRecord>> $validated Previously validated zones.
     *
     * @throws RuntimeException If a DNS query fails due to a transport or encoding error.
     * @throws InvalidArgumentException If the query name or options are invalid.
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     */
    private function validateZoneKeys(
        string $zone,
        array &$validated,
        CancellationTokenInterface $cancellation,
    ): null|TrustChainResult {
        if ($zone === '.') {
            return $this->validateRootZoneKeys($validated, $cancellation);
        }

        $fqdn = str_ends_with($zone, '.') ? $zone : $zone . '.';
        $dsResponse = $this->resolver->query($fqdn, RecordType::DS, $cancellation);
        $dsRecords = array_values(array_filter(
            $dsResponse->answers,
            static fn(RecordInterface $record): bool => (
                $record instanceof DSRecord
                && rtrim(strtolower($record->name), '.') === rtrim(strtolower($zone), '.')
            ),
        ));

        if ($dsRecords === []) {
            $failure = $this->validateDsNonExistence($zone, $dsResponse, $validated);
            if ($failure !== null) {
                return new TrustChainResult(TrustChainStatus::Bogus, [], $failure);
            }

            return new TrustChainResult(TrustChainStatus::Insecure, []);
        }

        $dsRrsigs = DNSSECVerification::filterRecords($dsResponse->answers, RecordType::RRSIG);
        if ($dsRrsigs === []) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::UnsignedDs);
        }

        $firstRrsig = $dsRrsigs[0];
        if ($firstRrsig instanceof RRSIGRecord) {
            $parentZone = $firstRrsig->signer === '' || $firstRrsig->signer === '.' ? '.' : $firstRrsig->signer;
            $parentKeys = $validated[$parentZone] ?? [];
            if ($parentKeys === []) {
                return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::ChainBroken);
            }

            $failure = self::verifyRrsigs($dsRecords, $dsRrsigs, $parentKeys);
            if ($failure !== null) {
                return new TrustChainResult(TrustChainStatus::Bogus, [], $failure);
            }
        }

        $dnskeyResponse = $this->resolver->query($fqdn, RecordType::DNSKEY, $cancellation);
        $dnskeys = DNSSECVerification::filterRecords($dnskeyResponse->answers, RecordType::DNSKEY);

        if ($dnskeys === []) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::MissingDnskey);
        }

        $rrsigs = DNSSECVerification::filterRecords($dnskeyResponse->answers, RecordType::RRSIG);
        if ($rrsigs === []) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::UnsignedDnskey);
        }

        if (!self::matchDsToKey($dsRecords, $dnskeys, $zone)) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::ChainBroken);
        }

        $failure = self::verifyDnskeyRrsig($dnskeys, $rrsigs, $dsRecords, $zone);
        if ($failure !== null) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], $failure);
        }

        $records = array_values(array_filter(
            $dnskeys,
            /**
             * @phpstan-assert-if-true DNSKEYRecord $r
             */
            static fn(RecordInterface $r): bool => $r instanceof DNSKEYRecord,
        ));

        $validated[$zone] = $records;
        return null;
    }

    /**
     * Validate root zone DNSKEY records.
     *
     * Returns null on success (populates $validated), or a Bogus result on failure.
     *
     * @param array<string, list<DNSKEYRecord>> $validated
     *
     * @throws RuntimeException If a DNS query fails due to a transport or encoding error.
     * @throws InvalidArgumentException If the query name or options are invalid.
     * @throws ProtocolException If a response is malformed or violates the DNS protocol.
     */
    private function validateRootZoneKeys(
        array &$validated,
        CancellationTokenInterface $cancellation,
    ): null|TrustChainResult {
        $dnskeyResponse = $this->resolver->query('.', RecordType::DNSKEY, $cancellation);
        $dnskeys = DNSSECVerification::filterRecords($dnskeyResponse->answers, RecordType::DNSKEY);

        if ($dnskeys === []) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::MissingDnskey);
        }

        $rrsigs = DNSSECVerification::filterRecords($dnskeyResponse->answers, RecordType::RRSIG);
        if ($rrsigs === []) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::UnsignedDnskey);
        }

        if (!self::matchDsToKey($this->trustAnchor->anchors, $dnskeys, '.')) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::TrustAnchorMismatch);
        }

        $failure = self::verifyDnskeyRrsig($dnskeys, $rrsigs, $this->trustAnchor->anchors, '.');
        if ($failure !== null) {
            return new TrustChainResult(TrustChainStatus::Bogus, [], $failure);
        }

        $records = array_values(array_filter(
            $dnskeys,
            /**
             * @phpstan-assert-if-true DNSKEYRecord $r
             */
            static fn(RecordInterface $r): bool => $r instanceof DNSKEYRecord,
        ));

        $validated['.'] = $records;

        return null;
    }

    /**
     * Validate that DS non-existence is properly proven via NSEC/NSEC3.
     *
     * Returns null if the proof is valid, or a ChainFailure reason if not.
     *
     * @param array<string, list<DNSKEYRecord>> $validated
     *
     * @throws DNSInvalidArgumentException If the zone name cannot be encoded.
     */
    private function validateDsNonExistence(string $zone, Response $dsResponse, array $validated): null|ChainFailure
    {
        $authority = array_values(array_filter(
            $dsResponse->authority,
            static fn(RecordInterface $record): bool => (
                $record->kind === RecordType::NSEC
                || $record->kind === RecordType::NSEC3
            ),
        ));

        $nsecPresent =
            DNSSECVerification::filterRecords($authority, RecordType::NSEC) !== []
            || DNSSECVerification::filterRecords($authority, RecordType::NSEC3) !== [];

        if (!$nsecPresent) {
            return ChainFailure::MissingDs;
        }

        $rrsigs = DNSSECVerification::filterRecords($dsResponse->authority, RecordType::RRSIG);
        if ($rrsigs === []) {
            return ChainFailure::UnsignedDs;
        }

        $firstRrsig = $rrsigs[0];
        if ($firstRrsig instanceof RRSIGRecord) {
            $parentZone = $firstRrsig->signer === '' || $firstRrsig->signer === '.' ? '.' : $firstRrsig->signer;
            $parentKeys = $validated[$parentZone] ?? [];
            if ($parentKeys === []) {
                return ChainFailure::ChainBroken;
            }

            $failure = self::verifyRrsigs($authority, $rrsigs, $parentKeys);
            if ($failure !== null) {
                return $failure;
            }
        }

        try {
            NSECProofValidator::validateDsNonExistence($zone, $authority);
        } catch (ProtocolException|DNSInvalidArgumentException|RuntimeException|Exception\RuntimeException) {
            return ChainFailure::MissingDs;
        }

        return null;
    }

    /**
     * Check if any DS record matches a DNSKEY.
     *
     * @param list<RecordInterface> $dsRecords
     * @param list<RecordInterface> $dnskeys
     *
     * @throws DNSInvalidArgumentException If the zone name cannot be encoded to wire format.
     * @throws RuntimeException If the hash computation fails.
     */
    private static function matchDsToKey(array $dsRecords, array $dnskeys, string $zone): bool
    {
        foreach ($dsRecords as $ds) {
            if (!$ds instanceof DSRecord || rtrim(strtolower($ds->name), '.') !== rtrim(strtolower($zone), '.')) {
                continue;
            }

            foreach ($dnskeys as $dnskey) {
                if (!$dnskey instanceof DNSKEYRecord) {
                    continue;
                }

                if ($dnskey->protocol !== 3 || ($dnskey->flags & 256) !== 256) {
                    continue;
                }

                if ($dnskey->algorithm !== $ds->algorithm) {
                    continue;
                }

                if (KeyTag::compute($dnskey) !== $ds->keyTag) {
                    continue;
                }

                if (DSVerifier::verify($ds, $dnskey, $zone)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verify the DNSKEY RRset with a key authenticated by DS or a trust anchor.
     *
     * @param list<RecordInterface> $dnskeys
     * @param list<RecordInterface> $rrsigs
     * @param list<RecordInterface> $dsRecords
     *
     * @throws DNSInvalidArgumentException If the zone name cannot be encoded.
     */
    private static function verifyDnskeyRrsig(
        array $dnskeys,
        array $rrsigs,
        array $dsRecords,
        string $zone,
    ): null|ChainFailure {
        $failure = self::enforceRecordLimits($dnskeys, $rrsigs);
        if ($failure !== null) {
            return $failure;
        }

        foreach ($rrsigs as $rrsig) {
            if (!$rrsig instanceof RRSIGRecord) {
                continue;
            }

            if ($rrsig->typeCovered !== RecordType::DNSKEY) {
                continue;
            }

            foreach ($dnskeys as $dnskey) {
                if (!$dnskey instanceof DNSKEYRecord) {
                    continue;
                }

                if (KeyTag::compute($dnskey) !== $rrsig->keyTag || $dnskey->algorithm !== $rrsig->algorithm) {
                    continue;
                }

                if (!self::matchDsToKey($dsRecords, [$dnskey], $zone)) {
                    continue;
                }

                $rrset = array_values(array_filter(
                    $dnskeys,
                    static fn(RecordInterface $r): bool => $r instanceof DNSKEYRecord,
                ));
                try {
                    if (RRSIGVerifier::verify($rrsig, $dnskey, $rrset)) {
                        return null;
                    }
                } catch (SignatureFailedException|InvalidArgumentException|LogicException) {
                    // @mago-expect lint:no-empty-catch-clause - continue to the next key
                }
            }
        }

        return ChainFailure::SignatureVerificationFailed;
    }

    /**
     * Require a valid RRSIG for every RRset.
     *
     * @param list<RecordInterface> $answers
     * @param list<RecordInterface> $rrsigs
     * @param list<DNSKEYRecord>    $dnskeys
     *
     * @throws DNSInvalidArgumentException If the zone name cannot be encoded.
     */
    private static function verifyRrsigs(array $answers, array $rrsigs, array $dnskeys): null|ChainFailure
    {
        $failure = self::enforceRecordLimits($dnskeys, $rrsigs);
        if ($failure !== null) {
            return $failure;
        }

        /** @var array<string, non-empty-list<RecordInterface>> $rrsets */
        $rrsets = [];
        foreach ($answers as $record) {
            $rrsets[rtrim(strtolower($record->name), '.') . ':' . $record->kind->value][] = $record;
        }

        foreach ($rrsets as $rrset) {
            $verified = false;
            foreach ($rrsigs as $rrsig) {
                if (
                    !$rrsig instanceof RRSIGRecord
                    || $rrsig->typeCovered !== $rrset[0]->kind
                    || rtrim(strtolower($rrsig->name), '.') !== rtrim(strtolower($rrset[0]->name), '.')
                ) {
                    continue;
                }

                foreach ($dnskeys as $dnskey) {
                    if (KeyTag::compute($dnskey) !== $rrsig->keyTag || $dnskey->algorithm !== $rrsig->algorithm) {
                        continue;
                    }

                    try {
                        $verified = RRSIGVerifier::verify($rrsig, $dnskey, $rrset);
                    } catch (SignatureFailedException|InvalidArgumentException|LogicException) {
                        continue;
                    }

                    if ($verified) {
                        break 2;
                    }
                }
            }

            if (!$verified) {
                return ChainFailure::SignatureVerificationFailed;
            }
        }

        return null;
    }

    /**
     * Check that DNSKEY and RRSIG record counts do not exceed safety limits.
     *
     * Returns null if within limits, or a ChainFailure reason if exceeded.
     *
     * @param list<RecordInterface>|list<DNSKEYRecord> $dnskeys
     * @param list<RecordInterface>                    $rrsigs
     */
    private static function enforceRecordLimits(array $dnskeys, array $rrsigs): null|ChainFailure
    {
        $dnskeyCount = count(array_filter($dnskeys, static fn(RecordInterface $r): bool => $r instanceof DNSKEYRecord));
        if ($dnskeyCount > 8) {
            return ChainFailure::ResourceExhaustion;
        }

        $rrsigCount = count(array_filter($rrsigs, static fn(RecordInterface $r): bool => $r instanceof RRSIGRecord));
        if ($rrsigCount > 8) {
            return ChainFailure::ResourceExhaustion;
        }

        return null;
    }
}
