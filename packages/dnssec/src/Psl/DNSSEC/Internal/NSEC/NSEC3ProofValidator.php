<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Internal\NSEC;

use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Internal\Base32Hex;
use Psl\DNS\Internal\DNSName;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC\Exception\InvalidProofException;

use function in_array;
use function strpos;
use function strtoupper;
use function substr;

/**
 * Validates NSEC3 denial-of-existence proofs per RFC 5155.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5155
 *
 * @internal
 *
 * @mago-expect lint:kan-defect
 */
final class NSEC3ProofValidator
{
    /**
     * NSEC3 NXDOMAIN per RFC 5155 Section 8.4.
     *
     * @param list<NSEC3Record> $nsec3Records
     *
     * @throws InvalidProofException If the NSEC3 parameters are invalid.
     * @throws InvalidProofException If the NSEC3 proof is invalid.
     * @throws InvalidProofException If the NSEC3 iteration count exceeds safety limits.
     * @throws InvalidArgumentException If the zone name cannot be encoded.
     * @throws InvalidProofException If the NSEC3 hash computation fails.
     */
    public static function validateNxdomain(string $queryName, array $nsec3Records): void
    {
        $first = $nsec3Records[0];
        $algorithm = $first->hashAlgorithm;
        $iterations = $first->iterations;
        $salt = $first->salt;

        foreach ($nsec3Records as $nsec3) {
            if ($nsec3->hashAlgorithm !== $algorithm || $nsec3->iterations !== $iterations || $nsec3->salt !== $salt) {
                throw InvalidProofException::forInconsistentParameters();
            }
        }

        $ancestors = DNSName::getAncestors($queryName);
        $closestEncloser = null;
        $closestEncloserIndex = -1;

        foreach ($ancestors as $index => $ancestor) {
            $hash = NSEC3Hash::compute($ancestor, $algorithm, $iterations, $salt);
            if ($hash === '') {
                throw InvalidProofException::forUnsupportedHashAlgorithm($algorithm);
            }

            $hashEncoded = Base32Hex::encode($hash);

            foreach ($nsec3Records as $nsec3) {
                if (!DNSName::caselessEquals(self::extractOwnerHash($nsec3), $hashEncoded)) {
                    continue;
                }

                $closestEncloser = $ancestor;
                $closestEncloserIndex = $index;
                break 2;
            }
        }

        if ($closestEncloser === null || $closestEncloserIndex <= 0) {
            throw InvalidProofException::forNoClosestEncloser($queryName);
        }

        $nextCloser = $ancestors[$closestEncloserIndex - 1];
        $nextCloserHash = Base32Hex::encode(NSEC3Hash::compute($nextCloser, $algorithm, $iterations, $salt));

        $nextCloserNsec3 = self::findCovering($nsec3Records, $nextCloserHash);
        if ($nextCloserNsec3 === null) {
            throw InvalidProofException::forNextCloserNotCovered($queryName);
        }

        $optOut = ($nextCloserNsec3->flags & 0x01) !== 0;
        if ($optOut) {
            return;
        }

        $wildcard = '*.' . $closestEncloser;
        $wildcardHash = Base32Hex::encode(NSEC3Hash::compute($wildcard, $algorithm, $iterations, $salt));

        if (!self::anyCovering($nsec3Records, $wildcardHash)) {
            throw InvalidProofException::forNSEC3WildcardNotCovered($queryName);
        }
    }

    /**
     * NSEC3 NODATA per RFC 5155 Section 8.5.
     *
     * @param list<NSEC3Record> $nsec3Records
     *
     * @throws InvalidProofException If the NSEC3 parameters are invalid.
     * @throws InvalidProofException If the NSEC3 proof is invalid.
     * @throws InvalidProofException If the NSEC3 iteration count exceeds safety limits.
     * @throws InvalidArgumentException If the zone name cannot be encoded.
     * @throws InvalidProofException If the NSEC3 hash computation fails.
     */
    public static function validateNodata(string $queryName, RecordType $queryKind, array $nsec3Records): void
    {
        $first = $nsec3Records[0];
        $algorithm = $first->hashAlgorithm;
        $iterations = $first->iterations;
        $salt = $first->salt;

        foreach ($nsec3Records as $nsec3) {
            if ($nsec3->hashAlgorithm !== $algorithm || $nsec3->iterations !== $iterations || $nsec3->salt !== $salt) {
                throw InvalidProofException::forInconsistentParameters();
            }
        }

        $hash = NSEC3Hash::compute($queryName, $algorithm, $iterations, $salt);
        if ($hash === '') {
            throw InvalidProofException::forUnsupportedHashAlgorithm($algorithm);
        }

        $hashEncoded = Base32Hex::encode($hash);

        foreach ($nsec3Records as $nsec3) {
            if (!DNSName::caselessEquals(self::extractOwnerHash($nsec3), $hashEncoded)) {
                continue;
            }

            foreach ($nsec3->types as $type) {
                if ($type === $queryKind) {
                    throw InvalidProofException::forNSEC3TypeExists($queryName, $queryKind->name);
                }
            }

            return;
        }

        throw InvalidProofException::forNSEC3NameNotMatched($queryName);
    }

    /**
     * Validate DS non-existence per RFC 5155 Sections 8.3 and 8.6.
     *
     * Handles both standard NODATA (exact match, DS not in bitmap) and
     * opt-out (a closest-encloser match and next-closer cover with the opt-out flag).
     *
     * @param list<NSEC3Record> $nsec3Records
     *
     * @return bool Whether the proof establishes an unsigned delegation.
     *
     * @throws InvalidProofException If the NSEC3 parameters are invalid.
     * @throws InvalidProofException If the NSEC3 proof is invalid.
     * @throws InvalidProofException If the NSEC3 iteration count exceeds safety limits.
     * @throws InvalidArgumentException If the zone name cannot be encoded.
     * @throws InvalidProofException If the NSEC3 hash computation fails.
     */
    public static function validateDsNonExistence(string $queryName, array $nsec3Records): bool
    {
        $first = $nsec3Records[0];
        $algorithm = $first->hashAlgorithm;
        $iterations = $first->iterations;
        $salt = $first->salt;

        foreach ($nsec3Records as $nsec3) {
            if ($nsec3->hashAlgorithm !== $algorithm || $nsec3->iterations !== $iterations || $nsec3->salt !== $salt) {
                throw InvalidProofException::forInconsistentParameters();
            }
        }

        $hash = NSEC3Hash::compute($queryName, $algorithm, $iterations, $salt);
        if ($hash === '') {
            throw InvalidProofException::forUnsupportedHashAlgorithm($algorithm);
        }

        $hashEncoded = Base32Hex::encode($hash);

        foreach ($nsec3Records as $nsec3) {
            if (!DNSName::caselessEquals(self::extractOwnerHash($nsec3), $hashEncoded)) {
                continue;
            }

            foreach ([RecordType::DS, RecordType::CNAME, RecordType::SOA] as $type) {
                if (in_array($type, $nsec3->types, true)) {
                    throw InvalidProofException::forNSEC3TypeExists($queryName, $type->name);
                }
            }

            return in_array(RecordType::NS, $nsec3->types, true);
        }

        $ancestors = DNSName::getAncestors($queryName);
        foreach ($ancestors as $index => $ancestor) {
            if ($index === 0) {
                continue;
            }

            $ancestorHash = Base32Hex::encode(NSEC3Hash::compute($ancestor, $algorithm, $iterations, $salt));
            foreach ($nsec3Records as $nsec3) {
                if (!DNSName::caselessEquals(self::extractOwnerHash($nsec3), $ancestorHash)) {
                    continue;
                }

                if (
                    in_array(RecordType::DNAME, $nsec3->types, true)
                    || in_array(RecordType::NS, $nsec3->types, true) && !in_array(RecordType::SOA, $nsec3->types, true)
                ) {
                    throw InvalidProofException::forDSNotProven($queryName);
                }

                $nextCloserHash = Base32Hex::encode(NSEC3Hash::compute(
                    $ancestors[$index - 1],
                    $algorithm,
                    $iterations,
                    $salt,
                ));
                $covering = self::findCovering($nsec3Records, $nextCloserHash);
                if ($covering !== null && ($covering->flags & 0x01) !== 0) {
                    return true;
                }

                throw InvalidProofException::forDSNotProven($queryName);
            }
        }

        throw InvalidProofException::forDSNotProven($queryName);
    }

    /**
     * Check if any NSEC3 record covers a hash value.
     *
     * @param list<NSEC3Record> $nsec3Records
     */
    private static function anyCovering(array $nsec3Records, string $hash): bool
    {
        return self::findCovering($nsec3Records, $hash) !== null;
    }

    /**
     * Find the NSEC3 record that covers a hash value.
     *
     * @param list<NSEC3Record> $nsec3Records
     */
    private static function findCovering(array $nsec3Records, string $hash): null|NSEC3Record
    {
        foreach ($nsec3Records as $nsec3) {
            if (self::coversHash($nsec3, $hash)) {
                return $nsec3;
            }
        }

        return null;
    }

    /**
     * Check if an NSEC3 record covers a hash value.
     *
     * The hash falls in the open interval (ownerHash, nextHashedOwnerName).
     * Handles wrap-around for the last NSEC3 in the zone.
     */
    private static function coversHash(NSEC3Record $nsec3, string $hash): bool
    {
        $owner = strtoupper(self::extractOwnerHash($nsec3));
        $next = strtoupper($nsec3->nextHashedOwnerName);
        $target = strtoupper($hash);

        if ($owner === $target || $next === $target) {
            return false;
        }

        if ($owner > $next) {
            return $target > $owner || $target < $next;
        }

        return $target > $owner && $target < $next;
    }

    /**
     * Extract the hashed owner name from the first label of an NSEC3 record's name.
     */
    private static function extractOwnerHash(NSEC3Record $nsec3): string
    {
        $dotPos = strpos($nsec3->name, '.');
        if ($dotPos === false) {
            return strtoupper($nsec3->name);
        }

        return strtoupper(substr($nsec3->name, 0, $dotPos));
    }
}
