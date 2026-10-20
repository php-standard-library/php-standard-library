<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\EDNS\PaddingOption;
use Psl\DNS\Internal\CachedResponse;
use Psl\DNS\Record;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\IP\Address;

use function get_object_vars;

final class CachedResponseTest extends TestCase
{
    #[DataProvider('records')]
    public function testCopiesRecordsWithoutChangingTheirData(Record\RecordInterface $record): void
    {
        $duration = $record->duration;
        $originalFields = get_object_vars($record);
        $response = new Response(
            42,
            ResponseCode::NoError,
            [$record],
            [$record],
            [$record],
            true,
            true,
            true,
            true,
            true,
        );
        $entry = new CachedResponse($response, Timestamp::monotonic()->minusSeconds(2), 10);
        $copy = $entry->response();

        static::assertNotSame($response, $copy);
        static::assertSame(42, $copy->id);
        static::assertSame(ResponseCode::NoError, $copy->code);
        static::assertTrue($copy->authoritativeAnswer);
        static::assertTrue($copy->recursionDesired);
        static::assertTrue($copy->recursionAvailable);
        static::assertTrue($copy->authenticatedData);
        static::assertTrue($copy->checkingDisabled);

        foreach ([$copy->answers, $copy->authority, $copy->additional] as $section) {
            static::assertCount(1, $section);
            $adjusted = $section[0];
            static::assertNotSame($record, $adjusted);
            static::assertSame($record::class, $adjusted::class);
            static::assertSame($record->kind, $adjusted->kind);
            static::assertGreaterThan(0, $adjusted->duration->getTotalSeconds());
            static::assertLessThanOrEqual(8, $adjusted->duration->getTotalSeconds());

            $expected = $originalFields;
            $actual = get_object_vars($adjusted);
            unset($expected['duration'], $actual['duration']);
            static::assertSame($expected, $actual);
        }

        static::assertSame($originalFields, get_object_vars($record));
        static::assertSame($duration, $record->duration);
    }

    public function testKeepsOptFlagsAndOptions(): void
    {
        $opt = new Record\OPTRecord('', Duration::seconds(32_768), 1232, 2, 1, true, [new PaddingOption(12)]);
        $response = new Response(1, ResponseCode::NoError, [], [], [$opt]);
        $entry = new CachedResponse($response, Timestamp::monotonic()->minusSeconds(2), 10);

        static::assertSame($opt, $entry->response()->additional[0]);
    }

    public function testCapsByCacheLifetimeAndEachRecordLifetime(): void
    {
        $long = new Record\TXTRecord('example.com', Duration::seconds(30), ['answer']);
        $short = new Record\TXTRecord('example.com', Duration::seconds(1), ['extra']);
        $response = new Response(1, ResponseCode::NoError, [$long], [], [$short]);
        $entry = new CachedResponse($response, Timestamp::monotonic()->minusSeconds(2), 10);
        $copy = $entry->response();

        static::assertLessThanOrEqual(8, $copy->answers[0]->duration->getTotalSeconds());
        static::assertGreaterThan(0, $copy->answers[0]->duration->getTotalSeconds());
        static::assertSame(0.0, $copy->additional[0]->duration->getTotalSeconds());
        static::assertSame(30.0, $long->duration->getTotalSeconds());
        static::assertSame(1.0, $short->duration->getTotalSeconds());
    }

    /** @return iterable<string, array{Record\RecordInterface}> */
    public static function records(): iterable
    {
        $name = 'Owner.Example.COM';
        $ttl = Duration::seconds(10);

        yield 'A' => [new Record\ARecord($name, $ttl, Address::parse('192.0.2.1'))];
        yield 'AAAA' => [new Record\AAAARecord($name, $ttl, Address::parse('2001:db8::1'))];
        yield 'CAA' => [new Record\CAARecord($name, $ttl, 128, 'issue', 'ca.example.com')];
        yield 'CNAME' => [new Record\CNAMERecord($name, $ttl, 'target.example.com')];
        yield 'DNAME' => [new Record\DNAMERecord($name, $ttl, 'target.example.com')];
        yield 'DNSKEY' => [new Record\DNSKEYRecord($name, $ttl, 257, 3, Algorithm::ED25519, 'public-key')];
        yield 'DS' => [new Record\DSRecord($name, $ttl, 1234, Algorithm::ED25519, DigestAlgorithm::SHA256, 'digest')];
        yield 'HTTPS' => [new Record\HTTPSRecord($name, $ttl, 1, 'target.example.com', [
            1 => "\x02h2",
            3 => "\x01\xbb",
        ])];
        yield 'LOC' => [new Record\LOCRecord(
            $name,
            $ttl,
            0,
            2_147_483_648,
            2_147_483_649,
            10_000_123,
            0x12,
            0x23,
            0x34,
        )];
        yield 'MX' => [new Record\MXRecord($name, $ttl, 10, 'mail.example.com')];
        yield 'NAPTR' => [new Record\NAPTRRecord($name, $ttl, 1, 2, 's', 'SIP+D2U', '!a!b!', 'sip.example.com')];
        yield 'NSEC' => [new Record\NSECRecord(
            $name,
            $ttl,
            'next.example.com',
            [Record\RecordType::A, Record\RecordType::RRSIG],
        )];
        yield 'NSEC3' => [new Record\NSEC3Record($name, $ttl, 1, 1, 3, 'salt', 'ABCDEF', [Record\RecordType::NS])];
        yield 'NSEC3PARAM' => [new Record\NSEC3PARAMRecord($name, $ttl, 1, 0, 3, 'salt')];
        yield 'NS' => [new Record\NSRecord($name, $ttl, 'ns.example.com')];
        yield 'PTR' => [new Record\PTRRecord($name, $ttl, 'target.example.com')];
        yield 'RRSIG' => [new Record\RRSIGRecord(
            $name,
            $ttl,
            Record\RecordType::A,
            Algorithm::ED25519,
            3,
            300,
            2_000_000_000,
            1_000_000_000,
            1234,
            'example.com',
            'signature',
        )];
        yield 'SOA' => [new Record\SOARecord(
            $name,
            $ttl,
            'ns.example.com',
            'admin.example.com',
            42,
            Duration::seconds(20),
            Duration::seconds(30),
            Duration::seconds(40),
            Duration::seconds(50),
        )];
        yield 'SRV' => [new Record\SRVRecord($name, $ttl, 1, 2, 443, 'target.example.com')];
        yield 'SSHFP' => [new Record\SSHFPRecord(
            $name,
            $ttl,
            Record\SSHFP\Algorithm::Ed25519,
            Record\SSHFP\FingerprintType::SHA256,
            'fingerprint',
        )];
        yield 'SVCB' => [new Record\SVCBRecord($name, $ttl, 1, 'target.example.com', [1 => "\x02h2", 3 => "\x01\xbb"])];
        yield 'TLSA' => [new Record\TLSARecord(
            $name,
            $ttl,
            Record\TLSA\CertificateUsage::DANE_EE,
            Record\TLSA\Selector::SubjectPublicKeyInfo,
            Record\TLSA\MatchingType::SHA256,
            'certificate',
        )];
        yield 'TXT' => [new Record\TXTRecord($name, $ttl, ['first', 'second'])];
    }
}
