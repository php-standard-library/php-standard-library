<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Internal\RRSIG;

use Psl\DateTime\Timestamp;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Internal\Base32Hex;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Internal\TypeBitmap;
use Psl\DNS\Record\AAAARecord;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CAARecord;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\HTTPSRecord;
use Psl\DNS\Record\LOCRecord;
use Psl\DNS\Record\MXRecord;
use Psl\DNS\Record\NAPTRRecord;
use Psl\DNS\Record\NSEC3PARAMRecord;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\NSRecord;
use Psl\DNS\Record\PTRRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\Record\SOARecord;
use Psl\DNS\Record\SRVRecord;
use Psl\DNS\Record\SSHFPRecord;
use Psl\DNS\Record\SVCBRecord;
use Psl\DNS\Record\TLSARecord;
use Psl\DNS\Record\TXTRecord;
use Psl\DNSSEC\Internal\SignatureVerifier;
use Psl\Exception\LogicException;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function hex2bin;
use function implode;
use function pack;
use function sort;
use function strlen;
use function strtolower;

/**
 * Verifies an RRSIG signature over an RRset per RFC 4034 Section 3.1.8.
 *
 * Handles building the canonical signed data from the RRSIG prefix and the
 * sorted canonical RRset, then delegates to the appropriate algorithm-specific
 * signature verification.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4034#section-3.1.8
 *
 * @internal
 */
final class RRSIGVerifier
{
    /**
     * Default clock skew tolerance in seconds (5 minutes).
     */
    private const int DEFAULT_CLOCK_SKEW_SECONDS = 300;

    /**
     * Verify an RRSIG record over the given RRset using a DNSKEY.
     *
     * @param list<RecordInterface> $rrset The records covered by the RRSIG, all of the same type and name.
     * @param non-negative-int $clockSkew Tolerance in seconds for clock differences (default: 300).
     *
     * @throws InvalidArgumentException If the RRSIG or DNSKEY format is invalid.
     * @throws \Psl\Crypto\Exception\InvalidArgumentException If the public key or signature length is invalid.
     * @throws LogicException If a record type is not supported for RDATA encoding.
     */
    public static function verify(
        RRSIGRecord $rrsig,
        DNSKEYRecord $dnskey,
        array $rrset,
        int $clockSkew = self::DEFAULT_CLOCK_SKEW_SECONDS,
    ): bool {
        if (!self::isWithinTimeWindow($rrsig, $clockSkew)) {
            return false;
        }

        $signedData = self::buildSignedData($rrsig, $rrset);

        return SignatureVerifier::verify($rrsig->algorithm, $rrsig->signature, $signedData, $dnskey->publicKey);
    }

    /**
     * Check whether the current time falls within the RRSIG validity window,
     * accounting for the given clock skew tolerance.
     *
     * @param non-negative-int $clockSkew Tolerance in seconds for clock differences.
     */
    public static function isWithinTimeWindow(
        RRSIGRecord $rrsig,
        int $clockSkew = self::DEFAULT_CLOCK_SKEW_SECONDS,
    ): bool {
        $now = Timestamp::now()->getSeconds();

        return $now >= ($rrsig->inception - $clockSkew) && $now <= ($rrsig->expiration + $clockSkew);
    }

    /**
     * Build the data that was signed: RRSIG RDATA prefix + canonical RRset.
     *
     * @param list<RecordInterface> $rrset
     *
     * @throws InvalidArgumentException If a domain name or encoding is invalid.
     *
     * @throws LogicException If the record type is not supported.
     */
    private static function buildSignedData(RRSIGRecord $rrsig, array $rrset): string
    {
        $signerWire = Encoder::encodeName($rrsig->signer);

        $rrsigPrefix =
            pack(
                'nCCNNNn',
                $rrsig->typeCovered->value,
                $rrsig->algorithm->value,
                $rrsig->labels,
                $rrsig->originalTtl,
                $rrsig->expiration,
                $rrsig->inception,
                $rrsig->keyTag,
            ) . $signerWire;

        $canonicalRrs = [];
        foreach ($rrset as $record) {
            $canonicalRrs[] = self::buildCanonicalRr($record, $rrsig);
        }

        sort($canonicalRrs);

        $result = $rrsigPrefix;
        foreach ($canonicalRrs as $rr) {
            $result .= $rr;
        }

        return $result;
    }

    /**
     * Build a canonical wire-format RR for inclusion in the signed data.
     *
     * Owner names are lowercased. TTL is set to the RRSIG's originalTtl.
     *
     * @throws InvalidArgumentException If a domain name or encoding is invalid.
     *
     * @throws LogicException If the record type is not supported.
     */
    private static function buildCanonicalRr(RecordInterface $record, RRSIGRecord $rrsig): string
    {
        $ownerName = strtolower($record->name);
        $labels = array_values(array_filter(explode('.', $ownerName), static fn(string $l): bool => $l !== ''));
        $labelCount = count($labels);
        if ($labelCount > $rrsig->labels) {
            /** @var non-negative-int $offset */
            $offset = $labelCount - $rrsig->labels;
            $closestEncloser = implode('.', array_slice($labels, $offset));
            $ownerName = '*.' . $closestEncloser;
        }

        $ownerWire = Encoder::encodeName($ownerName);

        $rdata = self::encodeRecordRdata($record);
        $rdlength = strlen($rdata);

        return $ownerWire . pack('nnNn', $rrsig->typeCovered->value, 1, $rrsig->originalTtl, $rdlength) . $rdata;
    }

    /**
     * Encode a record's RDATA back to wire format.
     *
     * This handles the common record types that appear in DNSSEC-signed zones.
     *
     * @throws InvalidArgumentException If a domain name or encoding is invalid.
     *
     * @throws LogicException If the record type is not supported.
     */
    private static function encodeRecordRdata(RecordInterface $record): string
    {
        if ($record instanceof ARecord || $record instanceof AAAARecord) {
            return $record->address->toBytes();
        }

        if ($record instanceof NSRecord) {
            return Encoder::encodeName(strtolower($record->host));
        }

        if ($record instanceof CNAMERecord) {
            return Encoder::encodeName(strtolower($record->target));
        }

        if ($record instanceof MXRecord) {
            return pack('n', $record->preference) . Encoder::encodeName(strtolower($record->exchange));
        }

        if ($record instanceof TXTRecord) {
            $result = '';
            foreach ($record->strings as $string) {
                $result .= pack('C', strlen($string)) . $string;
            }

            return $result;
        }

        if ($record instanceof SOARecord) {
            return (
                Encoder::encodeName(strtolower($record->masterName))
                . Encoder::encodeName(strtolower($record->responsibleName))
                . pack(
                    'NNNNN',
                    $record->serial,
                    (int) $record->refresh->getTotalSeconds(),
                    (int) $record->retry->getTotalSeconds(),
                    (int) $record->expire->getTotalSeconds(),
                    (int) $record->minimumTtl->getTotalSeconds(),
                )
            );
        }

        if ($record instanceof DSRecord) {
            $digestBytes = hex2bin($record->digest);
            if ($digestBytes === false) {
                $digestBytes = '';
            }

            return pack('nCC', $record->keyTag, $record->algorithm->value, $record->digestType->value) . $digestBytes;
        }

        if ($record instanceof DNSKEYRecord) {
            return pack('nCC', $record->flags, $record->protocol, $record->algorithm->value) . $record->publicKey;
        }

        if ($record instanceof NSECRecord) {
            return Encoder::encodeName(strtolower($record->nextDomainName)) . TypeBitmap::encode($record->types);
        }

        if ($record instanceof NSEC3Record) {
            if ($record->salt !== '') {
                $saltBytes = hex2bin($record->salt);
                if ($saltBytes === false) {
                    $saltBytes = '';
                }
            } else {
                $saltBytes = '';
            }

            $nextHashBytes = Base32Hex::decode($record->nextHashedOwnerName);

            return (
                pack('CCnC', $record->hashAlgorithm, $record->flags, $record->iterations, strlen($saltBytes))
                . $saltBytes
                . pack('C', strlen($nextHashBytes))
                . $nextHashBytes
                . TypeBitmap::encode($record->types)
            );
        }

        if ($record instanceof NSEC3PARAMRecord) {
            if ($record->salt !== '') {
                $saltBytes = hex2bin($record->salt);
                if ($saltBytes === false) {
                    $saltBytes = '';
                }
            } else {
                $saltBytes = '';
            }

            return (
                pack('CCnC', $record->hashAlgorithm, $record->flags, $record->iterations, strlen($saltBytes))
                . $saltBytes
            );
        }

        if ($record instanceof PTRRecord) {
            return Encoder::encodeName(strtolower($record->target));
        }

        if ($record instanceof CAARecord) {
            return pack('CC', $record->flags, strlen($record->tag)) . $record->tag . $record->value;
        }

        if ($record instanceof SRVRecord) {
            return (
                pack('nnn', $record->priority, $record->weight, $record->port)
                . Encoder::encodeName(strtolower($record->target))
            );
        }

        if ($record instanceof SSHFPRecord) {
            $fingerprintBytes = hex2bin($record->fingerprint);
            if ($fingerprintBytes === false) {
                $fingerprintBytes = '';
            }

            return pack('CC', $record->algorithm->value, $record->fingerprintType->value) . $fingerprintBytes;
        }

        if ($record instanceof TLSARecord) {
            $certDataBytes = hex2bin($record->certificateAssociationData);
            if (false === $certDataBytes) {
                $certDataBytes = '';
            }

            return (
                pack('CCC', $record->certificateUsage->value, $record->selector->value, $record->matchingType->value)
                . $certDataBytes
            );
        }

        if ($record instanceof LOCRecord) {
            return pack(
                'CCCCNNN',
                $record->version,
                $record->sizeRaw,
                $record->horizontalPrecisionRaw,
                $record->verticalPrecisionRaw,
                $record->latitudeRaw,
                $record->longitudeRaw,
                $record->altitudeRaw,
            );
        }

        if ($record instanceof NAPTRRecord) {
            return (
                pack('nnC', $record->order, $record->preference, strlen($record->flags))
                . $record->flags
                . pack('C', strlen($record->services))
                . $record->services
                . pack('C', strlen($record->regexp))
                . $record->regexp
                . Encoder::encodeName(strtolower($record->replacement))
            );
        }

        if ($record instanceof RRSIGRecord) {
            return (
                pack(
                    'nCCNNNn',
                    $record->typeCovered->value,
                    $record->algorithm->value,
                    $record->labels,
                    $record->originalTtl,
                    $record->expiration,
                    $record->inception,
                    $record->keyTag,
                )
                . Encoder::encodeName(strtolower($record->signer))
                . $record->signature
            );
        }

        if ($record instanceof SVCBRecord || $record instanceof HTTPSRecord) {
            $result = pack('n', $record->priority) . Encoder::encodeName(strtolower($record->target));
            foreach ($record->params as $key => $value) {
                $result .= pack('nn', $key, strlen($value)) . $value;
            }

            return $result;
        }

        throw new LogicException('Unhandled record type: ' . $record::class);
    }
}
