<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Internal\NSEC;

use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Internal\DNSName;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC\Exception\InvalidProofException;

use function in_array;
use function rtrim;
use function str_ends_with;
use function strtolower;

/**
 * Validates NSEC/NSEC3 denial-of-existence proofs per RFC 4034 and RFC 5155.
 *
 * Dispatches to NSEC or NSEC3 specific validation depending on which record
 * types are present in the authority section.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4034
 * @see https://datatracker.ietf.org/doc/html/rfc5155
 *
 * @internal
 *
 * @mago-expect lint:kan-defect - NSEC proof types need separate validation paths.
 */
final class NSECProofValidator
{
    /**
     * Validate denial-of-existence proof for an NXDOMAIN response.
     *
     * @param list<RecordInterface> $authorityRecords
     *
     * @throws InvalidProofException If the proof is invalid or incomplete.
     * @throws InvalidProofException If the NSEC3 iteration count exceeds safety limits.
     * @throws InvalidArgumentException If the zone name cannot be encoded.
     * @throws InvalidProofException If the NSEC3 hash computation fails.
     * @throws InvalidProofException If the NSEC proof is invalid.
     * @throws InvalidProofException If the NSEC3 proof is invalid.
     * @throws InvalidProofException If the NSEC3 parameters are invalid.
     */
    public static function validateNxdomain(string $queryName, array $authorityRecords): void
    {
        $nsecRecords = self::filterNsec($authorityRecords);
        $nsec3Records = self::filterNsec3($authorityRecords);

        if ($nsec3Records !== []) {
            NSEC3ProofValidator::validateNxdomain($queryName, $nsec3Records);
            return;
        }

        if ($nsecRecords !== []) {
            self::validateNsecNxdomain($queryName, $nsecRecords);
            return;
        }

        throw InvalidProofException::forNSECProof($queryName);
    }

    /**
     * Validate denial-of-existence proof for a NODATA response.
     *
     * @param list<RecordInterface> $authorityRecords
     *
     * @throws InvalidProofException If the proof is invalid or incomplete.
     * @throws InvalidProofException If the NSEC3 iteration count exceeds safety limits.
     * @throws InvalidArgumentException If the zone name cannot be encoded.
     * @throws InvalidProofException If the NSEC3 hash computation fails.
     * @throws InvalidProofException If the NSEC proof is invalid.
     * @throws InvalidProofException If the NSEC3 proof is invalid.
     * @throws InvalidProofException If the NSEC3 parameters are invalid.
     */
    public static function validateNodata(string $queryName, RecordType $queryKind, array $authorityRecords): void
    {
        $nsecRecords = self::filterNsec($authorityRecords);
        $nsec3Records = self::filterNsec3($authorityRecords);

        if ($nsec3Records !== []) {
            NSEC3ProofValidator::validateNodata($queryName, $queryKind, $nsec3Records);
            return;
        }

        if ($nsecRecords !== []) {
            self::validateNsecNodata($queryName, $queryKind, $nsecRecords);
            return;
        }

        throw InvalidProofException::forNODATAProof($queryName);
    }

    /**
     * NSEC NXDOMAIN: prove the name does not exist and no wildcard covers it.
     *
     * @param list<NSECRecord> $nsecRecords
     *
     * @throws InvalidProofException If the NSEC proof is invalid.
     */
    private static function validateNsecNxdomain(string $queryName, array $nsecRecords): void
    {
        $nameCovered = false;
        foreach ($nsecRecords as $nsec) {
            if (!self::nsecCoversName($nsec, $queryName)) {
                continue;
            }

            $nameCovered = true;
        }

        if (!$nameCovered) {
            throw InvalidProofException::forNameNotCovered($queryName);
        }

        $closestEncloser = self::findNsecClosestEncloser($queryName, $nsecRecords);
        $wildcard = '*.' . $closestEncloser;

        $wildcardCovered = false;
        foreach ($nsecRecords as $nsec) {
            if (DNSName::caselessEquals($nsec->name, $wildcard)) {
                throw InvalidProofException::forWildcardExists($wildcard, $queryName);
            }

            if (self::nsecCoversName($nsec, $wildcard)) {
                $wildcardCovered = true;
            }
        }

        if (!$wildcardCovered) {
            throw InvalidProofException::forWildcardNotCovered($queryName);
        }
    }

    /**
     * NSEC NODATA: the name exists but the queried type does not.
     *
     * @param list<NSECRecord> $nsecRecords
     *
     * @throws InvalidProofException If the NSEC proof is invalid.
     */
    private static function validateNsecNodata(string $queryName, RecordType $queryKind, array $nsecRecords): void
    {
        foreach ($nsecRecords as $nsec) {
            if (!DNSName::caselessEquals($nsec->name, $queryName)) {
                continue;
            }

            foreach ($nsec->types as $type) {
                if ($type === $queryKind) {
                    throw InvalidProofException::forTypeExists($queryName, $queryKind->name);
                }
            }

            return;
        }

        throw InvalidProofException::forNameNotMatched($queryName);
    }

    /**
     * Check if an NSEC record covers a name in canonical order.
     */
    private static function nsecCoversName(NSECRecord $nsec, string $name): bool
    {
        $owner = strtolower($nsec->name);
        $next = strtolower($nsec->nextDomainName);
        $target = strtolower($name);

        $ownerOrder = DNSName::canonicalOrder($owner, $next);

        if ($ownerOrder > 0) {
            return DNSName::canonicalOrder($target, $next) < 0 || DNSName::canonicalOrder($owner, $target) < 0;
        }

        return DNSName::canonicalOrder($owner, $target) < 0 && DNSName::canonicalOrder($target, $next) < 0;
    }

    /**
     * Find the closest encloser from NSEC owner/next names.
     *
     * @param list<NSECRecord> $nsecRecords
     */
    private static function findNsecClosestEncloser(string $queryName, array $nsecRecords): string
    {
        $ancestors = DNSName::getAncestors($queryName);

        foreach ($ancestors as $ancestor) {
            if ($ancestor === '.') {
                return '';
            }

            foreach ($nsecRecords as $nsec) {
                if (DNSName::caselessEquals($nsec->name, $ancestor)) {
                    return $ancestor;
                }

                if (DNSName::caselessEquals($nsec->nextDomainName, $ancestor)) {
                    return $ancestor;
                }

                $nsecZone = DNSName::getParentName($nsec->name);
                if ($nsecZone !== '.' && DNSName::caselessEquals($nsecZone, $ancestor)) {
                    return $ancestor;
                }
            }
        }

        return '';
    }

    /**
     * Validate DS non-existence proof, handling NSEC3 opt-out per RFC 5155 Section 8.6.
     *
     * @param list<RecordInterface> $authorityRecords
     *
     * @return bool Whether the proof establishes an unsigned delegation.
     *
     * @throws InvalidProofException If the required NSEC/NSEC3 proof records are missing.
     * @throws InvalidProofException If the NSEC3 iteration count exceeds safety limits.
     * @throws InvalidArgumentException If the zone name cannot be encoded.
     * @throws InvalidProofException If the NSEC3 hash computation fails.
     * @throws InvalidProofException If the NSEC proof is invalid.
     * @throws InvalidProofException If the NSEC3 proof is invalid.
     * @throws InvalidProofException If the NSEC3 parameters are invalid.
     */
    public static function validateDsNonExistence(string $queryName, array $authorityRecords): bool
    {
        $nsecRecords = self::filterNsec($authorityRecords);
        $nsec3Records = self::filterNsec3($authorityRecords);

        if ($nsec3Records !== []) {
            return NSEC3ProofValidator::validateDsNonExistence($queryName, $nsec3Records);
        }

        foreach ($nsecRecords as $nsec) {
            if (!DNSName::caselessEquals($nsec->name, $queryName)) {
                continue;
            }

            foreach ([RecordType::DS, RecordType::CNAME, RecordType::SOA] as $type) {
                if (in_array($type, $nsec->types, true)) {
                    throw InvalidProofException::forTypeExists($queryName, $type->name);
                }
            }

            return in_array(RecordType::NS, $nsec->types, true);
        }

        foreach ($nsecRecords as $nsec) {
            if (self::nsecCoversName($nsec, $queryName)) {
                if (
                    in_array(RecordType::NS, $nsec->types, true)
                    && !in_array(RecordType::SOA, $nsec->types, true)
                    && str_ends_with(rtrim(strtolower($queryName), '.'), '.' . rtrim(strtolower($nsec->name), '.'))
                ) {
                    throw InvalidProofException::forDSNotProven($queryName);
                }

                return false;
            }
        }

        throw $nsecRecords === []
            ? InvalidProofException::forDSProof($queryName)
            : InvalidProofException::forNameNotMatched($queryName);
    }

    /**
     * @param list<RecordInterface> $records
     *
     * @return list<NSECRecord>
     */
    private static function filterNsec(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            if (!$record instanceof NSECRecord) {
                continue;
            }

            $result[] = $record;
        }

        return $result;
    }

    /**
     * @param list<RecordInterface> $records
     *
     * @return list<NSEC3Record>
     */
    private static function filterNsec3(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            if (!$record instanceof NSEC3Record) {
                continue;
            }

            $result[] = $record;
        }

        return $result;
    }
}
