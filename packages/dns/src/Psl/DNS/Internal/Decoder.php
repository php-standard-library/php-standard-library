<?php

declare(strict_types=1);

namespace Psl\DNS\Internal;

use OutOfBoundsException;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Internal\EDNS\EDNSCodec;
use Psl\DNS\Record;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\IP\Address;
use ValueError;

use function bin2hex;
use function chr;
use function implode;
use function max;
use function ord;
use function strcasecmp;
use function strlen;
use function substr;
use function unpack;

/**
 * Parses DNS response packets per RFC 1035.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class Decoder
{
    /**
     * Maximum number of DNS compression pointer jumps to prevent infinite loops.
     */
    private const int MAX_POINTER_JUMPS = 15;

    /**
     * Decode a raw DNS response packet into a structured Response.
     *
     * @throws ProtocolException If the response is malformed, truncated, or violates the DNS protocol.
     */
    public static function decode(string $data): Response
    {
        try {
            return self::doDecode($data);
        } catch (ProtocolException $e) {
            throw $e;
            /** @mago-expect analysis:avoid-catching-error */
        } catch (OutOfBoundsException|ValueError|InvalidArgumentException $e) {
            throw ProtocolException::forDecodingFailure($e->getMessage(), $e);
        }
    }

    /**
     * Check whether the TC (truncation) flag is set in a DNS response.
     */
    public static function isTruncated(string $data): bool
    {
        if (strlen($data) < 12) {
            return false;
        }

        $flags = unpack('n', $data, 2)[1];

        return ($flags & 0x0200) !== 0;
    }

    /**
     * Match the response question to the encoded query (RFC 5452 section 9.1).
     * UDP error replies may omit the question when they contain no records.
     */
    public static function matchesQuestion(string $data, string $query, bool $allowEmptyError = false): bool
    {
        if (
            $allowEmptyError
            && strlen($data) === 12
            && (ord($data[3]) & 0x0F) !== 0
            && substr($data, 4) === "\0\0\0\0\0\0\0\0"
        ) {
            return true;
        }

        if (strlen($data) < 12 || substr($data, 4, 2) !== "\x00\x01") {
            return false;
        }

        try {
            $offset = 12;
            $queryOffset = 12;
            $name = self::readName($data, $offset, strlen($data), wire: true);
            $queryName = self::readName($query, $queryOffset, strlen($query), wire: true);

            return (
                strcasecmp($name, $queryName) === 0
                && self::readBytes($data, $offset, strlen($data), 4) === self::readBytes(
                    $query,
                    $queryOffset,
                    strlen($query),
                    4,
                )
            );
        } catch (OutOfBoundsException|ProtocolException $e) {
            return false;
        }
    }

    /**
     * Perform the actual packet decoding.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws InvalidArgumentException If record-specific encoding is invalid.
     * @throws ProtocolException If the record count exceeds safety limits.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function doDecode(string $data): Response
    {
        $length = strlen($data);
        if ($length < 12) {
            throw ProtocolException::forDecodingFailure(
                'Packet too short',
                new OutOfBoundsException('Packet too short'),
            );
        }

        /** @var array{1: int, 2: int, 3: int, 4: int, 5: int, 6: int} $header */
        $header = unpack('n6', $data, 0);
        $id = $header[1];
        $flags = $header[2];

        if (($flags & 0x8000) === 0) {
            throw ProtocolException::forQueryPacket();
        }

        $qdcount = $header[3];
        $ancount = $header[4];
        $nscount = $header[5];
        $arcount = $header[6];
        $offset = 12;

        $maxRecords = 4096;
        if ($qdcount > $maxRecords || $ancount > $maxRecords || $nscount > $maxRecords || $arcount > $maxRecords) {
            throw ProtocolException::forExcessiveRecordCount(max($qdcount, $ancount, $nscount, $arcount), $maxRecords);
        }

        $rcode = $flags & 0x000F;

        for ($i = 0; $i < $qdcount; $i++) {
            self::readName($data, $offset, $length);
            self::skip($offset, 4, $length);
        }

        $answers = self::readRecords($data, $offset, $length, $ancount);
        $authority = self::readRecords($data, $offset, $length, $nscount);
        $additional = self::readRecords($data, $offset, $length, $arcount);

        foreach ($additional as $record) {
            if (!$record instanceof Record\OPTRecord) {
                continue;
            }

            $rcode = ($record->extendedRcode << 4) | $rcode;
            break;
        }

        $responseCode = self::mapResponseCode($rcode);

        $aa = ($flags & 0x0400) !== 0;
        $rd = ($flags & 0x0100) !== 0;
        $ra = ($flags & 0x0080) !== 0;
        $ad = ($flags & 0x0020) !== 0;
        $cd = ($flags & 0x0010) !== 0;

        return new Response($id, $responseCode, $answers, $authority, $additional, $aa, $rd, $ra, $ad, $cd);
    }

    /**
     * Map a numeric DNS response code to its enum representation.
     *
     * @throws ProtocolException If the response code is not recognized.
     */
    private static function mapResponseCode(int $rcode): ResponseCode
    {
        return match ($rcode) {
            0 => ResponseCode::NoError,
            1 => ResponseCode::FormatError,
            2 => ResponseCode::ServerFailure,
            3 => ResponseCode::NonExistentDomain,
            4 => ResponseCode::NotImplemented,
            5 => ResponseCode::ServerRefused,
            6 => ResponseCode::DomainShouldNotExist,
            7 => ResponseCode::RecordSetShouldNotExist,
            8 => ResponseCode::RecordSetShouldExist,
            9 => ResponseCode::NotAuthoritative,
            10 => ResponseCode::NameNotInZone,
            16 => ResponseCode::BadVersion,
            23 => ResponseCode::BadCookie,
            default => throw ProtocolException::forUnknownResponseCode($rcode),
        };
    }

    /**
     * @param non-negative-int $count
     *
     * @throws OutOfBoundsException
     */
    private static function readBytes(string $data, int &$offset, int $length, int $count): string
    {
        if (($offset + $count) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $result = substr($data, $offset, $count);
        $offset += $count;

        return $result;
    }

    /**
     * Read a length-prefixed <character-string> (RFC 1035 section 3.3).
     *
     * @throws OutOfBoundsException
     */
    private static function readCharacterString(string $data, int &$offset, int $length): string
    {
        if ($offset >= $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $stringLength = ord($data[$offset++]);

        return $stringLength > 0 ? self::readBytes($data, $offset, $length, $stringLength) : '';
    }

    /**
     * @param non-negative-int $count
     *
     * @throws OutOfBoundsException
     */
    private static function skip(int &$offset, int $count, int $length): void
    {
        if (($offset + $count) > $length) {
            throw new OutOfBoundsException('Skip beyond end of data at offset ' . $offset);
        }

        $offset += $count;
    }

    /**
     * Read a sequence of resource records from the packet.
     *
     * @return list<Record\RecordInterface>
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws InvalidArgumentException If record-specific encoding is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readRecords(string $data, int &$offset, int $length, int $count): array
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $name = self::readName($data, $offset, $length);
            if (($offset + 10) > $length) {
                throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
            }

            /** @var array{type: int, class: int, ttl: int, rdlength: non-negative-int} $rr */
            $rr = unpack('ntype/nclass/Nttl/nrdlength', $data, $offset);
            $type = $rr['type'];
            $class = $rr['class'];
            $ttlSeconds = $rr['ttl'];
            $rd_length = $rr['rdlength'];
            $offset += 10;

            $duration = Duration::seconds($ttlSeconds);

            $cursorBefore = $offset;
            $record = self::readRecordData(
                $data,
                $offset,
                $length,
                $type,
                $name,
                $duration,
                $rd_length,
                $class,
                $ttlSeconds,
            );
            $consumed = $offset - $cursorBefore;

            if ($consumed < $rd_length) {
                /** @var non-negative-int $skip */
                $skip = $rd_length - $consumed;
                self::skip($offset, $skip, $length);
            } elseif ($consumed > $rd_length) {
                throw ProtocolException::forRDLengthMismatch($consumed, $rd_length);
            }

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Parse the RDATA section of a resource record based on its type.
     *
     * Returns null for unknown record types, skipping over their data.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws InvalidArgumentException If record-specific encoding is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readRecordData(
        string $data,
        int &$offset,
        int $length,
        int $type,
        string $name,
        Duration $duration,
        int $rd_length,
        int $class,
        int $ttlSeconds,
    ): null|Record\RecordInterface {
        $kind = Record\RecordType::tryFrom($type);
        if ($kind === null) {
            if ($rd_length > 0) {
                self::skip($offset, $rd_length, $length);
            }

            return null;
        }

        if ($kind !== Record\RecordType::OPT && $class !== 1) {
            throw ProtocolException::forUnsupportedClass($class);
        }

        return match ($kind) {
            Record\RecordType::A => self::readARecord($data, $offset, $length, $name, $duration),
            Record\RecordType::AAAA => self::readAaaaRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::CNAME => self::readCnameRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::DNAME => new Record\DNAMERecord(
                $name,
                $duration,
                self::readName($data, $offset, $length),
            ),
            Record\RecordType::MX => self::readMxRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::TXT => self::readTxtRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::SRV => self::readSrvRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::NS => self::readNsRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::PTR => self::readPtrRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::SOA => self::readSoaRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::CAA => self::readCaaRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::NAPTR => self::readNaptrRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::OPT => self::readOptRecord(
                $data,
                $offset,
                $length,
                $name,
                $duration,
                $class,
                $ttlSeconds,
                $rd_length,
            ),
            Record\RecordType::DS => self::readDsRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::SSHFP => self::readSshfpRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::RRSIG => self::readRrsigRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::NSEC => self::readNsecRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::DNSKEY => self::readDnskeyRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::NSEC3 => self::readNsec3Record($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::NSEC3PARAM => self::readNsec3paramRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::TLSA => self::readTlsaRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::LOC => self::readLocRecord($data, $offset, $length, $name, $duration),
            Record\RecordType::SVCB => self::readSvcbRecord($data, $offset, $length, $name, $duration, $rd_length),
            Record\RecordType::HTTPS => self::readHttpsRecord($data, $offset, $length, $name, $duration, $rd_length),
        };
    }

    /**
     * Read a 4-byte IPv4 address and construct an A record.
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readARecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\ARecord {
        /** @var non-empty-string $bytes */
        $bytes = self::readBytes($data, $offset, $length, 4);

        $address = Address::fromBytes($bytes);

        return new Record\ARecord($name, $duration, $address);
    }

    /**
     * Read a 16-byte IPv6 address and construct an AAAA record.
     *
     * The address is formatted per RFC 5952: leading zeros are stripped
     * from each group, and the longest run of consecutive all-zero groups
     * is collapsed to "::".
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readAaaaRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\AAAARecord {
        /** @var non-empty-string $bytes */
        $bytes = self::readBytes($data, $offset, $length, 16);

        $address = Address::fromBytes($bytes);

        return new Record\AAAARecord($name, $duration, $address);
    }

    /**
     * Read a CNAME record's target domain name.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readCnameRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\CNAMERecord {
        $target = self::readName($data, $offset, $length);

        return new Record\CNAMERecord($name, $duration, $target);
    }

    /**
     * Read a MX record's preference and exchange domain name.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readMxRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\MXRecord {
        if (($offset + 2) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $preference = unpack('n', $data, $offset)[1];
        $offset += 2;
        $exchange = self::readName($data, $offset, $length);

        return new Record\MXRecord($name, $duration, $preference, $exchange);
    }

    /**
     * Read a TXT record, concatenating all character strings within the RDATA.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readTxtRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): Record\TXTRecord {
        $strings = [];
        $bytesRead = 0;
        while ($bytesRead < $rd_length) {
            if ($offset >= $length) {
                throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
            }

            $strLen = ord($data[$offset++]);
            $bytesRead++;
            if ($strLen > 0) {
                $strings[] = self::readBytes($data, $offset, $length, $strLen);
                $bytesRead += $strLen;
            } else {
                $strings[] = '';
            }
        }

        if ($strings === []) {
            $strings = [''];
        }

        return new Record\TXTRecord($name, $duration, $strings);
    }

    /**
     * Read a SRV record's priority, weight, port, and target.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readSrvRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\SRVRecord {
        if (($offset + 6) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        /** @var array{1: int, 2: int, 3: int} $srv */
        $srv = unpack('n3', $data, $offset);
        $offset += 6;
        $target = self::readName($data, $offset, $length);

        return new Record\SRVRecord($name, $duration, $srv[1], $srv[2], $srv[3], $target);
    }

    /**
     * Read a NS record's authoritative nameserver hostname.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readNsRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\NSRecord {
        $host = self::readName($data, $offset, $length);

        return new Record\NSRecord($name, $duration, $host);
    }

    /**
     * Read a PTR record's target domain name.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readPtrRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\PTRRecord {
        $target = self::readName($data, $offset, $length);

        return new Record\PTRRecord($name, $duration, $target);
    }

    /**
     * Read a SOA record's primary nameserver, responsible party, serial,
     * and timing fields.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readSoaRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\SOARecord {
        $masterName = self::readName($data, $offset, $length);
        $responsibleName = self::readName($data, $offset, $length);
        if (($offset + 20) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        /** @var array{1: int, 2: int, 3: int, 4: int, 5: int} $soa */
        $soa = unpack('N5', $data, $offset);
        $offset += 20;
        $serial = $soa[1];
        $refresh = Duration::seconds($soa[2]);
        $retry = Duration::seconds($soa[3]);
        $expire = Duration::seconds($soa[4]);
        $minimumTtl = Duration::seconds($soa[5]);

        return new Record\SOARecord(
            $name,
            $duration,
            $masterName,
            $responsibleName,
            $serial,
            $refresh,
            $retry,
            $expire,
            $minimumTtl,
        );
    }

    /**
     * Read a CAA record's flags, tag, and value.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     * @throws InvalidArgumentException If record-specific encoding is invalid.
     */
    private static function readCaaRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): Record\CAARecord {
        if (($offset + 2) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $flags = ord($data[$offset]);
        $tagLength = ord($data[$offset + 1]);
        $offset += 2;
        if ($tagLength > ($rd_length - 2)) {
            throw InvalidArgumentException::forCAATagLengthOverflow($tagLength, $rd_length - 2);
        }

        /** @var positive-int $tagLength */
        $tag = self::readBytes($data, $offset, $length, $tagLength);
        $valueLength = $rd_length - 2 - $tagLength;
        /** @var non-negative-int $valueLength */
        $value = $valueLength > 0 ? self::readBytes($data, $offset, $length, $valueLength) : '';

        return new Record\CAARecord($name, $duration, $flags, $tag, $value);
    }

    /**
     * Read a NAPTR record's order, preference, flags, services, regexp, and replacement.
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readNaptrRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\NAPTRRecord {
        if (($offset + 4) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        /** @var array{1: int, 2: int} $naptr */
        $naptr = unpack('n2', $data, $offset);
        $order = $naptr[1];
        $preference = $naptr[2];
        $offset += 4;
        $flags = self::readCharacterString($data, $offset, $length);
        $services = self::readCharacterString($data, $offset, $length);
        $regexp = self::readCharacterString($data, $offset, $length);
        $replacement = self::readName($data, $offset, $length);

        return new Record\NAPTRRecord($name, $duration, $order, $preference, $flags, $services, $regexp, $replacement);
    }

    /**
     * Read an OPT pseudo-record (RFC 6891), decoding EDNS0 fields from the
     * class and TTL wire fields.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     * @throws ProtocolException If the response structure is invalid.
     */
    private static function readOptRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $class,
        int $ttlSeconds,
        int $rd_length,
    ): Record\OPTRecord {
        $udpPayloadSize = $class;
        $extendedRcode = ($ttlSeconds >> 24) & 0xFF;
        $version = ($ttlSeconds >> 16) & 0xFF;
        $dnssecOk = ($ttlSeconds & 0x8000) !== 0;
        $optData = $rd_length > 0 ? self::readBytes($data, $offset, $length, $rd_length) : '';
        $options = EDNSCodec::decodeOptions($optData);

        return new Record\OPTRecord($name, $duration, $udpPayloadSize, $extendedRcode, $version, $dnssecOk, $options);
    }

    /**
     * Read an SSHFP record's algorithm, fingerprint type, and hex-encoded fingerprint.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readSshfpRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): null|Record\SSHFPRecord {
        if (($offset + 2) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $algorithm = Record\SSHFP\Algorithm::tryFrom(ord($data[$offset]));
        $fingerprintType = Record\SSHFP\FingerprintType::tryFrom(ord($data[$offset + 1]));
        $offset += 2;
        if ($algorithm === null || $fingerprintType === null) {
            return null;
        }

        $fingerprintLength = $rd_length - 2;
        /** @var non-negative-int $fingerprintLength */
        $fingerprintBytes = $fingerprintLength > 0 ? self::readBytes($data, $offset, $length, $fingerprintLength) : '';
        $fingerprint = bin2hex($fingerprintBytes);

        return new Record\SSHFPRecord($name, $duration, $algorithm, $fingerprintType, $fingerprint);
    }

    /**
     * Read a TLSA record's certificate usage, selector, matching type,
     * and hex-encoded certificate association data.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readTlsaRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): null|Record\TLSARecord {
        if (($offset + 3) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $certificateUsage = Record\TLSA\CertificateUsage::tryFrom(ord($data[$offset]));
        $selector = Record\TLSA\Selector::tryFrom(ord($data[$offset + 1]));
        $matchingType = Record\TLSA\MatchingType::tryFrom(ord($data[$offset + 2]));
        $offset += 3;
        if ($certificateUsage === null || $selector === null || $matchingType === null) {
            return null;
        }

        $dataLength = $rd_length - 3;
        /** @var non-negative-int $dataLength */
        $dataBytes = $dataLength > 0 ? self::readBytes($data, $offset, $length, $dataLength) : '';
        $certificateAssociationData = bin2hex($dataBytes);

        return new Record\TLSARecord(
            $name,
            $duration,
            $certificateUsage,
            $selector,
            $matchingType,
            $certificateAssociationData,
        );
    }

    /**
     * Read a DS record's key tag, algorithm, digest type, and hex-encoded digest.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readDsRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): null|Record\DSRecord {
        if (($offset + 4) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $keyTag = unpack('n', $data, $offset)[1];
        $algorithm = Algorithm::tryFrom(ord($data[$offset + 2]));
        $digestType = DigestAlgorithm::tryFrom(ord($data[$offset + 3]));
        $offset += 4;
        if ($algorithm === null || $digestType === null) {
            return null;
        }

        $digestLength = $rd_length - 4;
        /** @var non-negative-int $digestLength */
        $digestBytes = $digestLength > 0 ? self::readBytes($data, $offset, $length, $digestLength) : '';
        $digest = bin2hex($digestBytes);

        return new Record\DSRecord($name, $duration, $keyTag, $algorithm, $digestType, $digest);
    }

    /**
     * Read a DNSKEY record's flags, protocol, algorithm, and raw public key.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readDnskeyRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): null|Record\DNSKEYRecord {
        if (($offset + 4) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $flags = unpack('n', $data, $offset)[1];
        $protocol = ord($data[$offset + 2]);
        $algorithm = Algorithm::tryFrom(ord($data[$offset + 3]));
        $offset += 4;
        if ($algorithm === null) {
            return null;
        }

        $keyLength = $rd_length - 4;
        /** @var non-negative-int $keyLength */
        $publicKey = $keyLength > 0 ? self::readBytes($data, $offset, $length, $keyLength) : '';

        return new Record\DNSKEYRecord($name, $duration, $flags, $protocol, $algorithm, $publicKey);
    }

    /**
     * Read an RRSIG record per RFC 4034 Section 3.1.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readRrsigRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): null|Record\RRSIGRecord {
        if ($rd_length < 18) {
            throw ProtocolException::forRRSIGTooShort($rd_length);
        }

        if (($offset + 18) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        /** @var array<string, int> $rrsig */
        $rrsig = unpack('ntypeCovered/Calgorithm/Clabels/NoriginalTtl/Nexpiration/Ninception/nkeyTag', $data, $offset);
        $typeCovered = Record\RecordType::tryFrom($rrsig['typeCovered']);
        if ($typeCovered === null) {
            /** @var non-negative-int $remaining */
            $remaining = $rd_length - 2;
            $offset += 2;
            self::skip($offset, $remaining, $length);
            return null;
        }

        $algorithm = Algorithm::tryFrom($rrsig['algorithm']);
        if ($algorithm === null) {
            /** @var non-negative-int $remaining */
            $remaining = $rd_length - 3;
            $offset += 3;
            self::skip($offset, $remaining, $length);
            return null;
        }

        $offset += 18;
        $labels = $rrsig['labels'];
        $originalTtl = $rrsig['originalTtl'];
        $expiration = $rrsig['expiration'];
        $inception = $rrsig['inception'];
        $keyTag = $rrsig['keyTag'];

        $signerStart = $offset;
        $signer = self::readName($data, $offset, $length);
        $signerWireLength = $offset - $signerStart;

        $signatureLength = $rd_length - 18 - $signerWireLength;
        if ($signatureLength < 0) {
            throw ProtocolException::forRRSIGSignerOverflow();
        }

        $signature = $signatureLength > 0 ? self::readBytes($data, $offset, $length, $signatureLength) : '';

        return new Record\RRSIGRecord(
            $name,
            $duration,
            $typeCovered,
            $algorithm,
            $labels,
            $originalTtl,
            $expiration,
            $inception,
            $keyTag,
            $signer,
            $signature,
        );
    }

    /**
     * Read an NSEC record's next domain name and type bitmap.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws InvalidArgumentException If record-specific encoding is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readNsecRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): Record\NSECRecord {
        $startPosition = $offset;
        $nextDomainName = self::readName($data, $offset, $length);
        $nameLength = $offset - $startPosition;
        $bitmapLength = $rd_length - $nameLength;
        if ($bitmapLength < 0) {
            throw ProtocolException::forNSECNextDomainOverflow();
        }

        $types = $bitmapLength > 0 ? TypeBitmap::decodeRaw($data, $offset, $length, $bitmapLength) : [];

        return new Record\NSECRecord($name, $duration, $nextDomainName, $types);
    }

    /**
     * Read an NSEC3 record per RFC 5155 Section 3.2.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     * @throws InvalidArgumentException If record-specific encoding is invalid.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     */
    private static function readNsec3Record(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): Record\NSEC3Record {
        $startPosition = $offset;
        if (($offset + 5) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $hashAlgorithm = ord($data[$offset]);
        $flags = ord($data[$offset + 1]);
        $iterations = unpack('n', $data, $offset + 2)[1];
        $saltLength = ord($data[$offset + 4]);
        $offset += 5;
        /** @var non-negative-int $saltLength */
        $saltBytes = $saltLength > 0 ? self::readBytes($data, $offset, $length, $saltLength) : '';
        $salt = bin2hex($saltBytes);
        if ($offset >= $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $hashLength = ord($data[$offset++]);
        /** @var non-negative-int $hashLength */
        $hashBytes = $hashLength > 0 ? self::readBytes($data, $offset, $length, $hashLength) : '';
        $nextHashedOwnerName = Base32Hex::encode($hashBytes);
        $consumed = $offset - $startPosition;
        $bitmapLength = $rd_length - $consumed;
        if ($bitmapLength < 0) {
            throw ProtocolException::forNSEC3DataOverflow();
        }

        $types = $bitmapLength > 0 ? TypeBitmap::decodeRaw($data, $offset, $length, $bitmapLength) : [];

        return new Record\NSEC3Record(
            $name,
            $duration,
            $hashAlgorithm,
            $flags,
            $iterations,
            $salt,
            $nextHashedOwnerName,
            $types,
        );
    }

    /**
     * Read an NSEC3PARAM record per RFC 5155 Section 4.2.
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readNsec3paramRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\NSEC3PARAMRecord {
        if (($offset + 5) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $hashAlgorithm = ord($data[$offset]);
        $flags = ord($data[$offset + 1]);
        $iterations = unpack('n', $data, $offset + 2)[1];
        $saltLength = ord($data[$offset + 4]);
        $offset += 5;
        /** @var non-negative-int $saltLength */
        $saltBytes = $saltLength > 0 ? self::readBytes($data, $offset, $length, $saltLength) : '';
        $salt = bin2hex($saltBytes);

        return new Record\NSEC3PARAMRecord($name, $duration, $hashAlgorithm, $flags, $iterations, $salt);
    }

    /**
     * Read a LOC record per RFC 1876.
     *
     * @throws OutOfBoundsException If the packet data is truncated or incomplete.
     */
    private static function readLocRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
    ): Record\LOCRecord {
        if (($offset + 16) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        /** @var array<string, int> $loc */
        $loc = unpack('Cversion/Csize/Chprecision/Cvprecision/Nlatitude/Nlongitude/Naltitude', $data, $offset);
        $offset += 16;

        return new Record\LOCRecord(
            $name,
            $duration,
            $loc['version'],
            $loc['latitude'],
            $loc['longitude'],
            $loc['altitude'],
            $loc['size'],
            $loc['hprecision'],
            $loc['vprecision'],
        );
    }

    /**
     * Read an SVCB record per RFC 9460.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readSvcbRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): Record\SVCBRecord {
        [$priority, $target, $params] = self::readServiceBindingParams($data, $offset, $length, $rd_length);

        return new Record\SVCBRecord($name, $duration, $priority, $target, $params);
    }

    /**
     * Read an HTTPS record per RFC 9460.
     *
     * @param non-negative-int $rd_length
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readHttpsRecord(
        string $data,
        int &$offset,
        int $length,
        string $name,
        Duration $duration,
        int $rd_length,
    ): Record\HTTPSRecord {
        [$priority, $target, $params] = self::readServiceBindingParams($data, $offset, $length, $rd_length);

        return new Record\HTTPSRecord($name, $duration, $priority, $target, $params);
    }

    /**
     * Parse the shared wire format for SVCB/HTTPS records.
     *
     * @param non-negative-int $rd_length
     *
     * @return array{int, string, array<int, string>}
     *
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If the response structure is invalid.
     * @throws ProtocolException If record data exceeds its declared length.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readServiceBindingParams(string $data, int &$offset, int $length, int $rd_length): array
    {
        $startPosition = $offset;
        if (($offset + 2) > $length) {
            throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
        }

        $priority = unpack('n', $data, $offset)[1];
        $offset += 2;
        $target = self::readName($data, $offset, $length);

        $consumed = $offset - $startPosition;
        $remaining = $rd_length - $consumed;

        $params = [];
        while ($remaining > 0) {
            if ($remaining < 4) {
                throw ProtocolException::forSVCBParamTruncated();
            }

            if (($offset + 4) > $length) {
                throw new OutOfBoundsException('Read beyond end of data at offset ' . $offset);
            }

            /** @var array{1: int, 2: int} $kv */
            $kv = unpack('n2', $data, $offset);
            $key = $kv[1];
            $valueLen = $kv[2];
            $offset += 4;
            $remaining -= 4;

            if ($remaining < $valueLen) {
                throw ProtocolException::forSVCBParamOverflow();
            }

            /** @var non-negative-int $valueLen */
            $value = $valueLen > 0 ? self::readBytes($data, $offset, $length, $valueLen) : '';
            $params[$key] = $value;
            $remaining -= $valueLen;
        }

        return [$priority, $target, $params];
    }

    /**
     * Read a DNS domain name from the packet, handling compression pointers
     * per RFC 1035 Section 4.1.4.
     *
     * Compression pointers are identified by the top two bits of a label
     * length byte both being set (0xC0 mask). The remaining 14 bits form
     * an offset into the packet where the name continues.
     *
     * A jump counter prevents infinite loops caused by cyclic pointer
     * references in malicious packets.
     *
     * @throws ProtocolException If a pointer loop is detected.
     * @throws OutOfBoundsException If a read offset exceeds the packet bounds.
     * @throws ProtocolException If DNS name compression is invalid.
     */
    private static function readName(string $data, int &$offset, int $length, bool $wire = false): string
    {
        $labels = [];
        $jumps = 0;
        $totalLength = 0;
        $nameStart = $offset;

        $currentOffset = $offset;
        $jumped = false;
        while (true) {
            if ($currentOffset >= $length) {
                throw new OutOfBoundsException('Read beyond end of data at offset ' . $currentOffset);
            }

            $lengthByte = ord($data[$currentOffset++]);

            if ($lengthByte === 0) {
                if (!$jumped) {
                    $offset = $currentOffset;
                }

                break;
            }

            if (($lengthByte & 0xC0) === 0xC0) {
                if (++$jumps > self::MAX_POINTER_JUMPS) {
                    throw ProtocolException::forPointerLoop();
                }

                if ($currentOffset >= $length) {
                    throw new OutOfBoundsException('Read beyond end of data at offset ' . $currentOffset);
                }

                $nextByte = ord($data[$currentOffset++]);
                if (!$jumped) {
                    $offset = $currentOffset;
                    $jumped = true;
                }

                $pointerOffset = (($lengthByte & 0x3F) << 8) | $nextByte;

                if ($pointerOffset >= $nameStart) {
                    throw ProtocolException::forForwardPointer($pointerOffset);
                }

                $currentOffset = $pointerOffset;
            } elseif ($lengthByte > 63) {
                throw ProtocolException::forLabelTooLong($lengthByte);
            } else {
                if (($currentOffset + $lengthByte) > $length) {
                    throw new OutOfBoundsException('Read beyond end of data at offset ' . $currentOffset);
                }

                $label = substr($data, $currentOffset, $lengthByte);
                $currentOffset += $lengthByte;
                if (!$jumped) {
                    $offset = $currentOffset;
                }

                $labels[] = $wire ? chr($lengthByte) . $label : $label;
                $totalLength += $lengthByte + 1;
                if ($totalLength > 253) {
                    throw ProtocolException::forCompressionNameTooLong();
                }
            }
        }

        return implode($wire ? '' : '.', $labels);
    }
}
