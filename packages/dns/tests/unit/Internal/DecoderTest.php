<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\Internal;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\EDNS\RawOption;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Internal\Decoder;
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
use Psl\DNS\Record\OPTRecord;
use Psl\DNS\Record\PTRRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\Record\SOARecord;
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
use Psl\DNS\ResponseCode;
use Psl\Iter;
use Psl\Str\Byte;

use function restore_error_handler;
use function set_error_handler;

final class DecoderTest extends TestCase
{
    public function testDecodeARecord(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x1234,
            rcode: 0,
            questions: [['example.com', 1, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 1,
                    'ttl' => 300,
                    'rdata' => "\x5D\xB8\xD8\x22",
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertSame(0x1234, $response->id);
        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertCount(1, $response->answers);

        $record = $response->answers[0];
        static::assertInstanceOf(ARecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertEquals(Duration::seconds(300), $record->duration);
        static::assertSame('93.184.216.34', $record->address->toString());
    }

    public function testDecodeAaaaRecord(): void
    {
        $rdata = "\x20\x01\x0d\xb8\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01";

        $packet = $this->buildResponsePacket(
            id: 0x5678,
            rcode: 0,
            questions: [['example.com', 28, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 28,
                    'ttl' => 600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(AAAARecord::class, $record);
        static::assertSame('2001:db8::1', $record->address->toString());
        static::assertEquals(Duration::seconds(600), $record->duration);
    }

    public function testDecodeCnameRecord(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x0001,
            rcode: 0,
            questions: [['www.example.com', 5, 1]],
            answers: [
                [
                    'name' => 'www.example.com',
                    'type' => 5,
                    'ttl' => 3600,
                    'rdata' => $this->encodeName('example.com'),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(CNAMERecord::class, $record);
        static::assertSame('www.example.com', $record->name);
        static::assertSame('example.com', $record->target);
    }

    public function testDecodeMxRecord(): void
    {
        $rdata = new Writer()
            ->u16(10)
            ->bytes($this->encodeName('mail.example.com'))
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0002,
            rcode: 0,
            questions: [['example.com', 15, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 15,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(MXRecord::class, $record);
        static::assertSame(10, $record->preference);
        static::assertSame('mail.example.com', $record->exchange);
    }

    public function testDecodeTxtRecord(): void
    {
        $txt = 'v=spf1 ~all';
        $rdata = Byte\chr(Byte\length($txt)) . $txt;

        $packet = $this->buildResponsePacket(
            id: 0x0003,
            rcode: 0,
            questions: [['example.com', 16, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 16,
                    'ttl' => 300,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(TXTRecord::class, $record);
        static::assertSame('v=spf1 ~all', $record->data);
    }

    public function testDecodeSrvRecord(): void
    {
        $rdata = new Writer()
            ->u16(10)
            ->u16(60)
            ->u16(5060)
            ->bytes($this->encodeName('sip.example.com'))
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0004,
            rcode: 0,
            questions: [['_sip._tcp.example.com', 33, 1]],
            answers: [
                [
                    'name' => '_sip._tcp.example.com',
                    'type' => 33,
                    'ttl' => 86_400,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(SRVRecord::class, $record);
        static::assertSame(10, $record->priority);
        static::assertSame(60, $record->weight);
        static::assertSame(5060, $record->port);
        static::assertSame('sip.example.com', $record->target);
    }

    public function testDecodeNsRecord(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x0005,
            rcode: 0,
            questions: [['example.com', 2, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 2,
                    'ttl' => 172_800,
                    'rdata' => $this->encodeName('ns1.example.com'),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(NSRecord::class, $record);
        static::assertSame('ns1.example.com', $record->host);
    }

    public function testDecodePtrRecord(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x0006,
            rcode: 0,
            questions: [['34.216.184.93.in-addr.arpa', 12, 1]],
            answers: [
                [
                    'name' => '34.216.184.93.in-addr.arpa',
                    'type' => 12,
                    'ttl' => 3600,
                    'rdata' => $this->encodeName('example.com'),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(PTRRecord::class, $record);
        static::assertSame('example.com', $record->target);
    }

    public function testDecodeSoaRecord(): void
    {
        $rdata = $this->encodeName('ns1.example.com') . $this->encodeName('admin.example.com') . new Writer()
            ->u32(2_024_010_101)
            ->u32(7200)
            ->u32(3600)
            ->u32(1_209_600)
            ->u32(86_400)
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0007,
            rcode: 0,
            questions: [['example.com', 6, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 6,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(SOARecord::class, $record);
        static::assertSame('ns1.example.com', $record->masterName);
        static::assertSame('admin.example.com', $record->responsibleName);
        static::assertSame(2_024_010_101, $record->serial);
        static::assertEquals(Duration::seconds(7200), $record->refresh);
        static::assertEquals(Duration::seconds(3600), $record->retry);
        static::assertEquals(Duration::seconds(1_209_600), $record->expire);
        static::assertEquals(Duration::seconds(86_400), $record->minimumTtl);
    }

    public function testDecodeCaaRecord(): void
    {
        $tag = 'issue';
        $value = 'letsencrypt.org';
        $rdata = Byte\chr(0) . Byte\chr(Byte\length($tag)) . $tag . $value;

        $packet = $this->buildResponsePacket(
            id: 0x0008,
            rcode: 0,
            questions: [['example.com', 257, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 257,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(CAARecord::class, $record);
        static::assertSame(0, $record->flags);
        static::assertSame('issue', $record->tag);
        static::assertSame('letsencrypt.org', $record->value);
    }

    public function testDecodeNaptrRecord(): void
    {
        $flags = 's';
        $services = 'SIP+D2U';
        $regexp = '';
        $replacement = $this->encodeName('_sip._udp.example.com');

        $rdata = new Writer()
            ->u16(100)
            ->u16(10)
            ->u8(Byte\length($flags))
            ->bytes($flags)
            ->u8(Byte\length($services))
            ->bytes($services)
            ->u8(Byte\length($regexp))
            ->bytes($replacement)
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0010,
            rcode: 0,
            questions: [['example.com', 35, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 35,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(NAPTRRecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame(100, $record->order);
        static::assertSame(10, $record->preference);
        static::assertSame('s', $record->flags);
        static::assertSame('SIP+D2U', $record->services);
        static::assertSame('', $record->regexp);
        static::assertSame('_sip._udp.example.com', $record->replacement);
    }

    public function testDecodeTruncatedNaptrRecordThrowsWithoutWarnings(): void
    {
        $rdata = new Writer()
            ->u16(100)
            ->u16(10)
            ->u8(1)
            ->bytes('s')
            ->u8(7)
            ->bytes('SIP+D2U')
            ->u8(0)
            ->bytes($this->encodeName('_sip._udp.example.com'))
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0010,
            rcode: 0,
            questions: [['example.com', 35, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 35,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $packetLength = Byte\length($packet);
        $rdataOffset = $packetLength - Byte\length($rdata);

        // Reading a length byte past the end of the packet must not emit an
        // "Uninitialized string offset" warning or the PHP 8.5 ord('') deprecation.
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        $rejected = 0;
        try {
            for ($length = $rdataOffset; $length < $packetLength; $length++) {
                try {
                    Decoder::decode(Byte\slice($packet, 0, $length));
                } catch (ProtocolException) {
                    $rejected++;
                }
            }
        } finally {
            restore_error_handler();
        }

        static::assertSame($packetLength - $rdataOffset, $rejected);
    }

    public function testDecodeSshfpRecord(): void
    {
        $fingerprint = "\xaa\xbb\xcc\xdd\xee\xff\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd";
        $rdata = new Writer()
            ->u8(1)
            ->u8(1)
            ->bytes($fingerprint)
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0011,
            rcode: 0,
            questions: [['example.com', 44, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 44,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(SSHFPRecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame(SSHFPAlgorithm::RSA, $record->algorithm);
        static::assertSame(FingerprintType::SHA1, $record->fingerprintType);
        static::assertSame('aabbccddeeff00112233445566778899aabbccdd', $record->fingerprint);
    }

    public function testDecodeTlsaRecord(): void
    {
        $certData = "\x01\x02\x03\x04\x05\x06\x07\x08";
        $rdata = new Writer()
            ->u8(3)
            ->u8(1)
            ->u8(1)
            ->bytes($certData)
            ->toString();

        $packet = $this->buildResponsePacket(
            id: 0x0012,
            rcode: 0,
            questions: [['_443._tcp.example.com', 52, 1]],
            answers: [
                [
                    'name' => '_443._tcp.example.com',
                    'type' => 52,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(TLSARecord::class, $record);
        static::assertSame('_443._tcp.example.com', $record->name);
        static::assertSame(CertificateUsage::DANE_EE, $record->certificateUsage);
        static::assertSame(Selector::SubjectPublicKeyInfo, $record->selector);
        static::assertSame(MatchingType::SHA256, $record->matchingType);
        static::assertSame('0102030405060708', $record->certificateAssociationData);
    }

    public function testDecodeOptRecord(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x0013)->u16(0x8180)->u16(1)->u16(0)->u16(0)->u16(1);

        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $writer = $writer->u8(0)->u16(41)->u16(4096)->u32(0x0000_8000)->u16(0);

        $response = Decoder::decode($writer->toString());

        static::assertCount(0, $response->answers);
        static::assertCount(1, $response->additional);
        $record = $response->additional[0];
        static::assertInstanceOf(OPTRecord::class, $record);
        static::assertSame('', $record->name);
        static::assertSame(4096, $record->udpPayloadSize);
        static::assertSame(0, $record->extendedRcode);
        static::assertSame(0, $record->version);
        static::assertTrue($record->dnssecOk);
        static::assertSame([], $record->options);
    }

    public function testDecodeOptRecordWithData(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x0014)->u16(0x8180)->u16(1)->u16(0)->u16(0)->u16(1);

        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);
        $optData = new Writer()
            ->u16(99)
            ->u16(2)
            ->u8(0xAB)
            ->u8(0xCD)
            ->toString();
        $writer = $writer
            ->u8(0)
            ->u16(41)
            ->u16(1232)
            ->u32(0x0001_0000)
            ->u16(Byte\length($optData))
            ->bytes($optData);

        $response = Decoder::decode($writer->toString());

        static::assertCount(1, $response->additional);
        $record = $response->additional[0];
        static::assertInstanceOf(OPTRecord::class, $record);
        static::assertSame(1232, $record->udpPayloadSize);
        static::assertSame(0, $record->extendedRcode);
        static::assertSame(1, $record->version);
        static::assertFalse($record->dnssecOk);
        static::assertCount(1, $record->options);
        static::assertInstanceOf(RawOption::class, $record->options[0]);
        static::assertSame(99, $record->options[0]->code);
    }

    public function testDecodeNxdomainResponse(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x0009,
            rcode: 3,
            questions: [['nonexistent.example.com', 1, 1]],
            answers: [],
        );

        $response = Decoder::decode($packet);

        static::assertSame(ResponseCode::NonExistentDomain, $response->code);
        static::assertCount(0, $response->answers);
    }

    public function testDecodeServerFailureResponse(): void
    {
        $packet = $this->buildResponsePacket(id: 0x000A, rcode: 2, questions: [['example.com', 1, 1]], answers: []);

        $response = Decoder::decode($packet);

        static::assertSame(ResponseCode::ServerFailure, $response->code);
    }

    public function testDecodeUnknownRecordTypeSkipped(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x000B,
            rcode: 0,
            questions: [['example.com', 99, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 99,
                    'ttl' => 300,
                    'rdata' => "\x01\x02\x03\x04",
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(0, $response->answers);
    }

    public function testDecodeNameCompression(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x00FF)->u16(0x8180)->u16(1)->u16(1)->u16(0)->u16(0);

        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $writer = $writer->u16(0xC00C)->u16(1)->u16(1)->u32(300)->u16(4)->u8(1)->u8(2)->u8(3)->u8(4);

        $response = Decoder::decode($writer->toString());

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(ARecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame('1.2.3.4', $record->address->toString());
    }

    public function testIsTruncated(): void
    {
        $writer = new Writer()->u16(0x0001)->u16(0x8200);

        $data = $writer->toString() . "\x00\x00\x00\x00\x00\x00\x00\x00";

        static::assertTrue(Decoder::isTruncated($data));
    }

    public function testIsNotTruncated(): void
    {
        $writer = new Writer()->u16(0x0001)->u16(0x8180);

        $data = $writer->toString() . "\x00\x00\x00\x00\x00\x00\x00\x00";

        static::assertFalse(Decoder::isTruncated($data));
    }

    public function testIsTruncatedWithShortData(): void
    {
        static::assertFalse(Decoder::isTruncated('short'));
    }

    public function testDecodeInvalidDataThrowsException(): void
    {
        $this->expectException(ProtocolException::class);

        Decoder::decode('invalid');
    }

    public function testDecodeEmptyDataThrowsException(): void
    {
        $this->expectException(ProtocolException::class);

        Decoder::decode('');
    }

    public function testDecodeMultipleAnswers(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x000C,
            rcode: 0,
            questions: [['example.com', 1, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 1,
                    'ttl' => 300,
                    'rdata' => "\x01\x02\x03\x04",
                ],
                [
                    'name' => 'example.com',
                    'type' => 1,
                    'ttl' => 300,
                    'rdata' => "\x05\x06\x07\x08",
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(2, $response->answers);
        static::assertInstanceOf(ARecord::class, $response->answers[0]);
        static::assertInstanceOf(ARecord::class, $response->answers[1]);
        static::assertSame('1.2.3.4', $response->answers[0]->address->toString());
        static::assertSame('5.6.7.8', $response->answers[1]->address->toString());
    }

    public function testDecodeAuthorityAndAdditionalSections(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x00DD)->u16(0x8180)->u16(1)->u16(0)->u16(1)->u16(1);

        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $nsRdata = $this->encodeName('ns1.example.com');
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(2)->u16(1)->u32(172_800);
        $writer = $writer->u16(Byte\length($nsRdata))->bytes($nsRdata);

        $writer = $writer->bytes($this->encodeName('ns1.example.com'));
        $writer = $writer->u16(1)->u16(1)->u32(172_800);
        $writer = $writer->u16(4)->u8(198)->u8(51)->u8(100)->u8(1);

        $response = Decoder::decode($writer->toString());

        static::assertCount(0, $response->answers);
        static::assertCount(1, $response->authority);
        static::assertCount(1, $response->additional);
        static::assertInstanceOf(NSRecord::class, $response->authority[0]);
        static::assertInstanceOf(ARecord::class, $response->additional[0]);
    }

    public function testDecodeUnknownResponseCodeThrowsException(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('DNS response contains unknown response code');

        $packet = $this->buildResponsePacket(id: 0x00EE, rcode: 15, questions: [['example.com', 1, 1]], answers: []);

        Decoder::decode($packet);
    }

    public function testDecodeTxtRecordMultipleStrings(): void
    {
        $txt1 = 'part1';
        $txt2 = 'part2';
        $rdata = Byte\chr(Byte\length($txt1)) . $txt1 . Byte\chr(Byte\length($txt2)) . $txt2;

        $packet = $this->buildResponsePacket(
            id: 0x000D,
            rcode: 0,
            questions: [['example.com', 16, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 16,
                    'ttl' => 300,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(TXTRecord::class, $record);
        static::assertSame('part1part2', $record->data);
    }

    public function testDecodeAllResponseCodes(): void
    {
        $codes = [
            0 => 'NOERROR',
            1 => 'FORMERR',
            2 => 'SERVFAIL',
            3 => 'NXDOMAIN',
            4 => 'NOTIMP',
            5 => 'REFUSED',
            6 => 'YXDOMAIN',
            7 => 'YXRRSET',
            8 => 'NXRRSET',
            9 => 'NOTAUTH',
            10 => 'NOTZONE',
        ];

        foreach ($codes as $rcode => $expected) {
            $packet = $this->buildResponsePacket(
                id: $rcode,
                rcode: $rcode,
                questions: [['example.com', 1, 1]],
                answers: [],
            );

            $response = Decoder::decode($packet);
            static::assertSame($expected, $response->code->value, 'Failed for rcode ' . (string) $rcode);
        }
    }

    public function testDecodePointerLoopDetected(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('DNS forward compression pointer');

        $writer = new Writer();

        $writer = $writer->u16(0x00FF)->u16(0x8180)->u16(1)->u16(1)->u16(0)->u16(0);
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $answerOffset = Byte\length($writer->toString());
        $pointer = 0xC000 | $answerOffset;
        $writer = $writer->u16($pointer);
        $writer = $writer->u16(1)->u16(1)->u32(300)->u16(4);
        $writer = $writer->u8(1)->u8(2)->u8(3)->u8(4);

        Decoder::decode($writer->toString());
    }

    public function testDecodePointerBeyondPacketThrowsException(): void
    {
        $this->expectException(ProtocolException::class);

        $writer = new Writer();

        $writer = $writer->u16(0x00FF)->u16(0x8180)->u16(1)->u16(1)->u16(0)->u16(0);
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $writer = $writer->u16(0xC0FF);
        $writer = $writer->u16(1)->u16(1)->u32(300)->u16(4);
        $writer = $writer->u8(1)->u8(2)->u8(3)->u8(4);

        Decoder::decode($writer->toString());
    }

    public function testDecodeNestedPointerChain(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x00FF)->u16(0x8180)->u16(1)->u16(1)->u16(0)->u16(0);
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $writer = $writer->u16(0xC00C);
        $writer = $writer->u16(1)->u16(1)->u32(300)->u16(4);
        $writer = $writer->u8(10)->u8(20)->u8(30)->u8(40);

        $response = Decoder::decode($writer->toString());

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(ARecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame('10.20.30.40', $record->address->toString());
    }

    public function testDecodeMutualPointerLoopDetected(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('DNS forward compression pointer');

        $header = new Writer()
            ->u16(0x00FF)
            ->u16(0x8180)
            ->u16(0)
            ->u16(1)
            ->u16(0)
            ->u16(0)
            ->toString();

        $pointerA = Byte\chr(0xC0) . Byte\chr(14);
        $pointerB = Byte\chr(0xC0) . Byte\chr(12);

        $packet = $header . $pointerA . $pointerB;

        $answerPart = new Writer()
            ->u16(1)
            ->u16(1)
            ->u32(300)
            ->u16(4)
            ->u8(1)
            ->u8(2)
            ->u8(3)
            ->u8(4)
            ->toString();

        $packet = $header . $pointerA . $pointerB . $answerPart;

        Decoder::decode($packet);
    }

    public function testDecodePointerSelfReferenceDetected(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('DNS forward compression pointer');

        $writer = new Writer();
        $writer = $writer->u16(0x00FF)->u16(0x8180)->u16(0)->u16(1)->u16(0)->u16(0);

        $writer = $writer->u8(0xC0)->u8(12);
        $writer = $writer->u16(1)->u16(1)->u32(300)->u16(4);
        $writer = $writer->u8(1)->u8(2)->u8(3)->u8(4);

        Decoder::decode($writer->toString());
    }

    public function testDecodeTruncatedRecordDataThrowsException(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x00FF)->u16(0x8180)->u16(1)->u16(1)->u16(0)->u16(0);
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1)->u32(300)->u16(4);
        $writer = $writer->u8(1)->u8(2);

        $this->expectException(ProtocolException::class);

        Decoder::decode($writer->toString());
    }

    public function testDecodeDsRecord(): void
    {
        $rdata = new Writer();
        $rdata = $rdata->u16(12_345)->u8(8)->u8(2)->bytes("\xAA\xBB\xCC\xDD");

        $packet = $this->buildResponsePacket(
            id: 0x00A1,
            rcode: 0,
            questions: [['example.com', 43, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 43,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(DSRecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame(12_345, $record->keyTag);
        static::assertSame(Algorithm::RSASHA256, $record->algorithm);
        static::assertSame(DigestAlgorithm::SHA256, $record->digestType);
        static::assertSame('aabbccdd', $record->digest);
    }

    public function testDecodeDnskeyRecord(): void
    {
        $publicKey = "\x01\x02\x03\x04\x05\x06\x07\x08";
        $rdata = new Writer();
        $rdata = $rdata->u16(257)->u8(3)->u8(8)->bytes($publicKey);

        $packet = $this->buildResponsePacket(
            id: 0x00A2,
            rcode: 0,
            questions: [['example.com', 48, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 48,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(DNSKEYRecord::class, $record);
        static::assertSame(257, $record->flags);
        static::assertSame(3, $record->protocol);
        static::assertSame(Algorithm::RSASHA256, $record->algorithm);
        static::assertSame($publicKey, $record->publicKey);
    }

    public function testDecodeRrsigRecord(): void
    {
        $signerName = $this->encodeName('example.com');
        $signature = "\xDE\xAD\xBE\xEF";
        $rdata = new Writer();
        $rdata = $rdata
            ->u16(1)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(1_700_000_000)
            ->u32(1_699_900_000)
            ->u16(12_345)
            ->bytes($signerName)
            ->bytes($signature);

        $packet = $this->buildResponsePacket(
            id: 0x00A3,
            rcode: 0,
            questions: [['example.com', 46, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 46,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(RRSIGRecord::class, $record);
        static::assertSame(RecordType::A, $record->typeCovered);
        static::assertSame(Algorithm::RSASHA256, $record->algorithm);
        static::assertSame(2, $record->labels);
        static::assertSame(300, $record->originalTtl);
        static::assertSame(1_700_000_000, $record->expiration);
        static::assertSame(1_699_900_000, $record->inception);
        static::assertSame(12_345, $record->keyTag);
        static::assertSame('example.com', $record->signer);
        static::assertSame($signature, $record->signature);
    }

    public function testDecodeNsecRecord(): void
    {
        $nextName = $this->encodeName('www.example.com');
        $bitmap = "\x00\x04\x40\x00\x00\x08";
        $rdata = $nextName . $bitmap;

        $packet = $this->buildResponsePacket(
            id: 0x00A4,
            rcode: 0,
            questions: [['example.com', 47, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 47,
                    'ttl' => 3600,
                    'rdata' => $rdata,
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(NSECRecord::class, $record);
        static::assertSame('www.example.com', $record->nextDomainName);
        static::assertCount(2, $record->types);
        static::assertSame(RecordType::A, $record->types[0]);
        static::assertSame(RecordType::AAAA, $record->types[1]);
    }

    public function testDecodeNsec3Record(): void
    {
        $salt = "\xAA\xBB";
        $hash = "\x01\x02\x03\x04\x05";
        $bitmap = "\x00\x01\x40";

        $rdata = new Writer();
        $rdata = $rdata->u8(1)->u8(0)->u16(10)->u8(2)->bytes($salt)->u8(5)->bytes($hash)->bytes($bitmap);

        $packet = $this->buildResponsePacket(
            id: 0x00A5,
            rcode: 0,
            questions: [['example.com', 50, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 50,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(NSEC3Record::class, $record);
        static::assertSame(1, $record->hashAlgorithm);
        static::assertSame(0, $record->flags);
        static::assertSame(10, $record->iterations);
        static::assertSame('aabb', $record->salt);
        static::assertNotEmpty($record->nextHashedOwnerName);
        static::assertCount(1, $record->types);
        static::assertSame(RecordType::A, $record->types[0]);
    }

    public function testDecodeNsec3paramRecord(): void
    {
        $salt = "\xCC\xDD";
        $rdata = new Writer();
        $rdata = $rdata->u8(1)->u8(0)->u16(5)->u8(2)->bytes($salt);

        $packet = $this->buildResponsePacket(
            id: 0x00A6,
            rcode: 0,
            questions: [['example.com', 51, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 51,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(NSEC3PARAMRecord::class, $record);
        static::assertSame(1, $record->hashAlgorithm);
        static::assertSame(0, $record->flags);
        static::assertSame(5, $record->iterations);
        static::assertSame('ccdd', $record->salt);
    }

    public function testDecodeLocRecord(): void
    {
        $rdata = new Writer();
        $rdata = $rdata->u8(0)->u8(0x12)->u8(0x16)->u8(0x13)->u32(2_335_463_649)->u32(2_223_083_648)->u32(10_010_000);

        $packet = $this->buildResponsePacket(
            id: 0x00B0,
            rcode: 0,
            questions: [['example.com', 29, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 29,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(LOCRecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame(0, $record->version);
        static::assertSame(2_335_463_649, $record->latitudeRaw);
        static::assertSame(2_223_083_648, $record->longitudeRaw);
        static::assertSame(10_010_000, $record->altitudeRaw);
        static::assertSame(0x12, $record->sizeRaw);
        static::assertSame(0x16, $record->horizontalPrecisionRaw);
        static::assertSame(0x13, $record->verticalPrecisionRaw);
        static::assertEqualsWithDelta(52.216_667, $record->latitude, 0.001);
        static::assertEqualsWithDelta(21.0, $record->longitude, 0.001);
        static::assertEqualsWithDelta(100.0, $record->altitude, 0.01);
        static::assertEqualsWithDelta(1.0, $record->size, 0.01);
        static::assertEqualsWithDelta(10_000.0, $record->horizontalPrecision, 0.01);
        static::assertEqualsWithDelta(10.0, $record->verticalPrecision, 0.01);
    }

    public function testDecodeLocRecordSeaLevel(): void
    {
        $rdata = new Writer();
        $rdata = $rdata->u8(0)->u8(0x00)->u8(0x00)->u8(0x00)->u32(2_147_483_648)->u32(2_147_483_648)->u32(10_000_000);

        $packet = $this->buildResponsePacket(
            id: 0x00B9,
            rcode: 0,
            questions: [['origin.example.com', 29, 1]],
            answers: [
                [
                    'name' => 'origin.example.com',
                    'type' => 29,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(LOCRecord::class, $record);
        static::assertSame(2_147_483_648, $record->latitudeRaw);
        static::assertSame(2_147_483_648, $record->longitudeRaw);
        static::assertSame(10_000_000, $record->altitudeRaw);
        static::assertSame(0x00, $record->sizeRaw);
        static::assertEqualsWithDelta(0.0, $record->latitude, 0.001);
        static::assertEqualsWithDelta(0.0, $record->longitude, 0.001);
        static::assertEqualsWithDelta(0.0, $record->altitude, 0.01);
        static::assertEqualsWithDelta(0.0, $record->size, 0.01);
    }

    public function testDecodeSvcbRecord(): void
    {
        $paramValue = "\x00\x02h2";
        $rdata = new Writer();
        $rdata = $rdata
            ->u16(1)
            ->bytes($this->encodeName('svc.example.com'))
            ->u16(1)
            ->u16(Byte\length($paramValue))
            ->bytes($paramValue);

        $packet = $this->buildResponsePacket(
            id: 0x00B1,
            rcode: 0,
            questions: [['example.com', 64, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 64,
                    'ttl' => 300,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(SVCBRecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame(1, $record->priority);
        static::assertSame('svc.example.com', $record->target);
        static::assertCount(1, $record->params);
        static::assertSame($paramValue, $record->params[1]);
    }

    public function testDecodeHttpsRecord(): void
    {
        $paramValue = "\x00\x02h2";
        $rdata = new Writer();
        $rdata = $rdata
            ->u16(1)
            ->bytes($this->encodeName('cdn.example.com'))
            ->u16(1)
            ->u16(Byte\length($paramValue))
            ->bytes($paramValue);

        $packet = $this->buildResponsePacket(
            id: 0x00B2,
            rcode: 0,
            questions: [['example.com', 65, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 65,
                    'ttl' => 300,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(HTTPSRecord::class, $record);
        static::assertSame('example.com', $record->name);
        static::assertSame(1, $record->priority);
        static::assertSame('cdn.example.com', $record->target);
        static::assertCount(1, $record->params);
        static::assertSame($paramValue, $record->params[1]);
    }

    public function testDecodeSvcbAliasMode(): void
    {
        $rdata = new Writer();
        $rdata = $rdata->u16(0)->bytes($this->encodeName('other.example.com'));

        $packet = $this->buildResponsePacket(
            id: 0x00B3,
            rcode: 0,
            questions: [['example.com', 64, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 64,
                    'ttl' => 300,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(SVCBRecord::class, $record);
        static::assertSame(0, $record->priority);
        static::assertSame('other.example.com', $record->target);
        static::assertCount(0, $record->params);
    }

    public function testDecodeExtendedRcodeFromOptRecord(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x0020)->u16(0x8180)->u16(1)->u16(0)->u16(0)->u16(1);

        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $extRcode = 1;
        $ttlField = ($extRcode << 24) | 0x0000_8000;
        $writer = $writer->u8(0)->u16(41)->u16(4096)->u32($ttlField)->u16(0);

        $response = Decoder::decode($writer->toString());

        static::assertSame(ResponseCode::BadVersion, $response->code);
    }

    public function testDecodeBadCookieCombinesHeaderAndOptResponseCodes(): void
    {
        $packet = new Writer()
            ->u16(0x0020)
            ->u16(0x8187)
            ->u16(1)
            ->u16(0)
            ->u16(0)
            ->u16(1)
            ->bytes($this->encodeName('example.com'))
            ->u16(1)
            ->u16(1)
            ->u8(0)
            ->u16(41)
            ->u16(1232)
            ->u32(1 << 24)
            ->u16(0)
            ->toString();

        static::assertSame('BADCOOKIE', Decoder::decode($packet)->code->value);
    }

    public function testDecodeUnknownExtendedResponseCodeThrowsException(): void
    {
        $packet = new Writer()
            ->u16(0x0020)
            ->u16(0x818F)
            ->u16(1)
            ->u16(0)
            ->u16(0)
            ->u16(1)
            ->bytes($this->encodeName('example.com'))
            ->u16(1)
            ->u16(1)
            ->u8(0)
            ->u16(41)
            ->u16(1232)
            ->u32(255 << 24)
            ->u16(0)
            ->toString();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('4095');

        Decoder::decode($packet);
    }

    public function testDecodeSvcbParamValueExceedsRdata(): void
    {
        $rdata = new Writer();
        $rdata = $rdata->u16(1)->bytes($this->encodeName('svc.example.com'))->u16(1)->u16(9999)->toString();

        $packet = $this->buildResponsePacket(
            id: 0x00C0,
            rcode: 0,
            questions: [['example.com', 64, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 64,
                    'ttl' => 300,
                    'rdata' => $rdata,
                ],
            ],
        );

        $this->expectException(ProtocolException::class);

        Decoder::decode($packet);
    }

    public function testDecodeRrsigWithUnknownTypeCoveredSkipped(): void
    {
        $signerName = $this->encodeName('example.com');
        $signature = "\xDE\xAD\xBE\xEF";
        $rdata = new Writer();
        $rdata = $rdata
            ->u16(999)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32(1_700_000_000)
            ->u32(1_699_900_000)
            ->u16(12_345)
            ->bytes($signerName)
            ->bytes($signature);

        $packet = $this->buildResponsePacket(
            id: 0x00C1,
            rcode: 0,
            questions: [['example.com', 46, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 46,
                    'ttl' => 3600,
                    'rdata' => $rdata->toString(),
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(0, $response->answers);
    }

    public function testDecodeTtlMaxU32(): void
    {
        $packet = $this->buildResponsePacket(
            id: 0x00D1,
            rcode: 0,
            questions: [['example.com', 1, 1]],
            answers: [
                [
                    'name' => 'example.com',
                    'type' => 1,
                    'ttl' => 0xFFFF_FFFF,
                    'rdata' => "\x01\x02\x03\x04",
                ],
            ],
        );

        $response = Decoder::decode($packet);

        static::assertCount(1, $response->answers);
        $record = $response->answers[0];
        static::assertInstanceOf(ARecord::class, $record);
        static::assertEquals(Duration::seconds(0xFFFF_FFFF), $record->duration);
    }

    public function testDecodeOptRecordEdnsVersion(): void
    {
        $writer = new Writer();

        $writer = $writer->u16(0x0021)->u16(0x8180)->u16(1)->u16(0)->u16(0)->u16(1);

        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $version = 0;
        $ttlField = ($version << 16) | 0x0000_8000;
        $writer = $writer->u8(0)->u16(41)->u16(4096)->u32($ttlField)->u16(0);

        $response = Decoder::decode($writer->toString());

        static::assertSame(ResponseCode::NoError, $response->code);
        $opt = null;
        foreach ($response->additional as $record) {
            if (!$record instanceof OPTRecord) {
                continue;
            }

            $opt = $record;
        }

        static::assertNotNull($opt);
        static::assertSame(0, $opt->version);
        static::assertTrue($opt->dnssecOk);
    }

    public function testDecodeRejectsQueryPacket(): void
    {
        $writer = new Writer();
        $writer = $writer->u16(0x1234)->u16(0x0100)->u16(1)->u16(0)->u16(0)->u16(0);
        $writer = $writer->bytes($this->encodeName('example.com'));
        $writer = $writer->u16(1)->u16(1);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('query packet');

        Decoder::decode($writer->toString());
    }

    public function testDecodeRecordCountExceedsLimitThrows(): void
    {
        $writer = new Writer();
        $writer = $writer->u16(0x00D0)->u16(0x8180)->u16(0)->u16(10_000)->u16(0)->u16(0);

        $this->expectException(ProtocolException::class);

        Decoder::decode($writer->toString());
    }

    /**
     * @param list<array{string, int, int}> $questions
     * @param list<array{name: string, type: int, ttl: int, rdata: string}> $answers
     */
    private function buildResponsePacket(int $id, int $rcode, array $questions, array $answers): string
    {
        $writer = new Writer();

        $flags = 0x8180 | ($rcode & 0x0F);
        $writer = $writer
            ->u16($id)
            ->u16($flags)
            ->u16(Iter\count($questions))
            ->u16(Iter\count($answers))
            ->u16(0)
            ->u16(0);

        foreach ($questions as [$name, $qtype, $qclass]) {
            $writer = $writer->bytes($this->encodeName($name));
            $writer = $writer->u16($qtype)->u16($qclass);
        }

        foreach ($answers as $answer) {
            $writer = $writer->bytes($this->encodeName($answer['name']));
            $writer = $writer->u16($answer['type'])->u16(1);
            $writer = $writer->u32($answer['ttl']);
            $writer = $writer->u16(Byte\length($answer['rdata']));
            $writer = $writer->bytes($answer['rdata']);
        }

        return $writer->toString();
    }

    private function encodeName(string $name): string
    {
        $parts = Byte\split($name, '.');
        $encoded = '';
        foreach ($parts as $part) {
            $encoded .= Byte\chr(Byte\length($part)) . $part;
        }

        return $encoded . "\x00";
    }
}
