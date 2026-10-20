<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit\Internal;

use ErrorException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CAARecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\HTTPSRecord;
use Psl\DNS\Record\LOCRecord;
use Psl\DNS\Record\NAPTRRecord;
use Psl\DNS\Record\PTRRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\Record\SRVRecord;
use Psl\DNS\Record\SSHFP\Algorithm as SSHFPAlgorithm;
use Psl\DNS\Record\SSHFP\FingerprintType;
use Psl\DNS\Record\SSHFPRecord;
use Psl\DNS\Record\SVCBRecord;
use Psl\DNS\Record\TLSA\CertificateUsage;
use Psl\DNS\Record\TLSA\MatchingType;
use Psl\DNS\Record\TLSA\Selector;
use Psl\DNS\Record\TLSARecord;
use Psl\DNS\Record\TXTRecord;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\Encoding\Hex;
use Psl\IP\Address;
use Psl\Str;
use Psl\Str\Byte;
use RuntimeException;

use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function restore_error_handler;
use function set_error_handler;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

final class RrsigVerifierTest extends TestCase
{
    public function testVerifyRsaSha256Rrsig(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('93.184.216.34'));

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::A->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $rdata = "\x5D\xB8\xD8\x22";
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::A->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        $result = openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$result) {
            throw new RuntimeException('Failed to sign data with OpenSSL');
        }

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$aRecord]));
    }

    public function testVerifyRejectsInvalidSignature(): void
    {
        $rawKey = "\x03\x01\x00\x01" . Str\repeat("\xAB", 128);
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            KeyTag::compute($dnskey),
            'example.com',
            'invalid-signature-data',
        );

        static::assertFalse(RRSIGVerifier::verify($rrsig, $dnskey, [$aRecord]));
    }

    public function testVerifyRejectsExpiredRrsig(): void
    {
        $rawKey = "\x03\x01\x00\x01" . Str\repeat("\xAB", 128);
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            1_600_000_000,
            1_500_000_000,
            KeyTag::compute($dnskey),
            'example.com',
            'signature',
        );

        static::assertFalse(RRSIGVerifier::verify($rrsig, $dnskey, [$aRecord]));
    }

    public function testVerifyRejectsFutureInception(): void
    {
        $rawKey = "\x03\x01\x00\x01" . Str\repeat("\xAB", 128);
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            3_000_000_000,
            2_900_000_000,
            KeyTag::compute($dnskey),
            'example.com',
            'signature',
        );

        static::assertFalse(RRSIGVerifier::verify($rrsig, $dnskey, [$aRecord]));
    }

    public function testVerifyWildcardSynthesis(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('foo.example.com', Duration::seconds(300), Address::v4('93.184.216.34'));

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::A->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $wildcardOwnerWire = Encoder::encodeName('*.example.com');
        $rdata = "\x5D\xB8\xD8\x22";
        $canonicalRr = new Writer()
            ->bytes($wildcardOwnerWire)
            ->u16(RecordType::A->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        $result = openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$result) {
            throw new RuntimeException('Failed to sign data with OpenSSL');
        }

        $rrsig = new RRSIGRecord(
            'foo.example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$aRecord]));
    }

    public function testVerifyTxtMultiStringPreservesStringBoundaries(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $string1 = Str\repeat('A', 100);
        $string2 = Str\repeat('B', 100);
        $txtRecord = new TXTRecord('example.com', Duration::seconds(300), [$string1, $string2]);

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::TXT->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $rdata = Byte\chr(100) . $string1 . Byte\chr(100) . $string2;

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::TXT->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        $result = openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$result) {
            throw new RuntimeException('Failed to sign data with OpenSSL');
        }

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::TXT,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$txtRecord]));
    }

    public function testVerifyTxtStringLongerThan255BytesKeepsWrappedLengthByte(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        // Strings longer than 255 bytes can only come from a user-constructed record. The canonical
        // encoding keeps the low byte of the length (300 & 0xFF = 0x2C), as it did before PHP 8.5.
        $string = Str\repeat('A', 300);
        $txtRecord = new TXTRecord('example.com', Duration::seconds(300), [$string]);

        $rrsigPrefix = new Writer()
            ->u16(RecordType::TXT->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes(Encoder::encodeName('example.com'))
            ->toString();

        $rdata = "\x2C" . $string;
        $canonicalRr = new Writer()
            ->bytes(Encoder::encodeName('example.com'))
            ->u16(RecordType::TXT->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signature = null;
        if (!openssl_sign($rrsigPrefix . $canonicalRr, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign data with OpenSSL');
        }

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::TXT,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        // Passing a length above 255 to chr() is deprecated since PHP 8.5.
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$txtRecord]));
        } finally {
            restore_error_handler();
        }
    }

    public function testVerifyPtrRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord(
            '168.192.in-addr.arpa',
            Duration::seconds(3600),
            257,
            3,
            Algorithm::RSASHA256,
            $rawKey,
        );
        $keyTag = KeyTag::compute($dnskey);

        $ptrRecord = new PTRRecord('1.0.168.192.in-addr.arpa', Duration::seconds(300), 'host.example.com');

        $rdata = Encoder::encodeName(Byte\lowercase('host.example.com'));

        $signerWire = Encoder::encodeName('168.192.in-addr.arpa');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::PTR->value)
            ->u8(8)
            ->u8(6)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('1.0.168.192.in-addr.arpa');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::PTR->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '1.0.168.192.in-addr.arpa',
            Duration::seconds(3600),
            RecordType::PTR,
            Algorithm::RSASHA256,
            6,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            '168.192.in-addr.arpa',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$ptrRecord]));
    }

    public function testVerifyCaaRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $caaRecord = new CAARecord('example.com', Duration::seconds(300), 0, 'issue', 'letsencrypt.org');

        $rdata = Byte\chr(0) . Byte\chr(5) . 'issueletsencrypt.org';

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::CAA->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::CAA->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::CAA,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$caaRecord]));
    }

    public function testVerifySrvRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $srvRecord = new SRVRecord('_sip._tcp.example.com', Duration::seconds(300), 10, 60, 5060, 'sip.example.com');

        $rdata = new Writer()
            ->u16(10)
            ->u16(60)
            ->u16(5060)
            ->bytes(Encoder::encodeName(Byte\lowercase('sip.example.com')))
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::SRV->value)
            ->u8(8)
            ->u8(4)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('_sip._tcp.example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::SRV->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '_sip._tcp.example.com',
            Duration::seconds(3600),
            RecordType::SRV,
            Algorithm::RSASHA256,
            4,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$srvRecord]));
    }

    public function testVerifySshfpRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $sshfpRecord = new SSHFPRecord(
            'example.com',
            Duration::seconds(300),
            SSHFPAlgorithm::RSA,
            FingerprintType::SHA256,
            'aabbccdd',
        );

        $rdata = new Writer()
            ->u8(1)
            ->u8(2)
            ->bytes(Hex\decode('aabbccdd'))
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::SSHFP->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::SSHFP->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::SSHFP,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$sshfpRecord]));
    }

    public function testVerifyTlsaRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $tlsaRecord = new TLSARecord(
            '_443._tcp.example.com',
            Duration::seconds(300),
            CertificateUsage::DANE_EE,
            Selector::SubjectPublicKeyInfo,
            MatchingType::SHA256,
            'aabbccdd',
        );

        $rdata = new Writer()
            ->u8(3)
            ->u8(1)
            ->u8(1)
            ->bytes(Hex\decode('aabbccdd'))
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::TLSA->value)
            ->u8(8)
            ->u8(4)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('_443._tcp.example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::TLSA->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '_443._tcp.example.com',
            Duration::seconds(3600),
            RecordType::TLSA,
            Algorithm::RSASHA256,
            4,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$tlsaRecord]));
    }

    public function testVerifyLocRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $locRecord = new LOCRecord(
            'example.com',
            Duration::seconds(300),
            0,
            2_335_463_649,
            2_223_083_648,
            10_010_000,
            0x12,
            0x16,
            0x13,
        );

        $rdata = new Writer()
            ->u8(0)
            ->u8($locRecord->sizeRaw)
            ->u8($locRecord->horizontalPrecisionRaw)
            ->u8($locRecord->verticalPrecisionRaw)
            ->u32($locRecord->latitudeRaw)
            ->u32($locRecord->longitudeRaw)
            ->u32($locRecord->altitudeRaw)
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::LOC->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::LOC->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::LOC,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$locRecord]));
    }

    public function testVerifySvcbRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $paramValue = "\x00\x02h2";
        $svcbRecord = new SVCBRecord('example.com', Duration::seconds(300), 1, 'svc.example.com', [1 => $paramValue]);

        $rdata = new Writer()
            ->u16(1)
            ->bytes(Encoder::encodeName(Byte\lowercase('svc.example.com')))
            ->u16(1)
            ->u16(Byte\length($paramValue))
            ->bytes($paramValue)
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::SVCB->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::SVCB->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::SVCB,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$svcbRecord]));
    }

    public function testVerifyHttpsRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $httpsRecord = new HTTPSRecord('example.com', Duration::seconds(300), 1, 'cdn.example.com', []);

        $rdata = new Writer()
            ->u16(1)
            ->bytes(Encoder::encodeName(Byte\lowercase('cdn.example.com')))
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::HTTPS->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::HTTPS->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::HTTPS,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$httpsRecord]));
    }

    public function testVerifyNaptrRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $naptrRecord = new NAPTRRecord(
            'example.com',
            Duration::seconds(300),
            100,
            10,
            's',
            'SIP+D2U',
            '',
            '_sip._udp.example.com',
        );

        $rdata = new Writer()
            ->u16(100)
            ->u16(10)
            ->u8(1)
            ->bytes('s')
            ->u8(7)
            ->bytes('SIP+D2U')
            ->u8(0)
            ->bytes(Encoder::encodeName(Byte\lowercase('_sip._udp.example.com')))
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::NAPTR->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::NAPTR->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::NAPTR,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($rrsig, $dnskey, [$naptrRecord]));
    }

    public function testVerifyRrsigRecordRdataEncoding(): void
    {
        [$rawKey, $key] = self::generateRsaKey();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $innerSignature = Str\repeat("\xAB", 64);
        $innerRrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(300),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            12_345,
            'example.com',
            $innerSignature,
        );

        $rdata = new Writer()
            ->u16(RecordType::A->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16(12_345)
            ->bytes(Encoder::encodeName(Byte\lowercase('example.com')))
            ->bytes($innerSignature)
            ->toString();

        $signerWire = Encoder::encodeName('example.com');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::RRSIG->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(2_000_000_000)
            ->u32(1_000_000_000)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName('example.com');
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::RRSIG->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        $signedData = $rrsigPrefix . $canonicalRr;

        $signature = null;
        openssl_sign($signedData, $signature, $key, OPENSSL_ALGO_SHA256);

        $outerRrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::RRSIG,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag,
            'example.com',
            $signature,
        );

        static::assertTrue(RRSIGVerifier::verify($outerRrsig, $dnskey, [$innerRrsig]));
    }

    /** @return array{string, OpenSSLAsymmetricKey} */
    private static function generateRsaKey(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Failed to generate RSA key pair');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Failed to get RSA key details');
        }

        if (!isset($details['rsa']['e']) || !is_string($details['rsa']['e'])) {
            throw new RuntimeException('Missing RSA exponent in key details');
        }

        if (!isset($details['rsa']['n']) || !is_string($details['rsa']['n'])) {
            throw new RuntimeException('Missing RSA modulus in key details');
        }

        $exponent = $details['rsa']['e'];
        $modulus = $details['rsa']['n'];
        $expLen = Byte\length($exponent);

        if ($expLen < 256) {
            return [Byte\chr($expLen) . $exponent . $modulus, $key];
        }

        return ["\x00" . Byte\chr(($expLen >> 8) & 0xFF) . Byte\chr($expLen & 0xFF) . $exponent . $modulus, $key];
    }
}
