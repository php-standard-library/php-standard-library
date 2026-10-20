<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\Internal\Base32Hex;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Internal\NSEC\NSEC3Hash;
use Psl\DNSSEC\Internal\NSEC\NSECProofValidator;
use Psl\Str\Byte;

final class NsecProofValidatorAdditionalTest extends TestCase
{
    public function testNsecNxdomainThrowsWhenNameNotCovered(): void
    {
        $nsec = new NSECRecord(
            'zzz.example.com',
            Duration::seconds(3600),
            'zzzz.example.com',
            [RecordType::A, RecordType::RRSIG, RecordType::NSEC],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No NSEC record covers the query name');

        NSECProofValidator::validateNxdomain('beta.example.com', [$nsec]);
    }

    public function testNsecNxdomainThrowsWhenWildcardExists(): void
    {
        $nsec1 = new NSECRecord(
            'alpha.example.com',
            Duration::seconds(3600),
            'gamma.example.com',
            [RecordType::A, RecordType::RRSIG, RecordType::NSEC],
        );

        $nsecWildcard = new NSECRecord(
            '*.example.com',
            Duration::seconds(3600),
            'alpha.example.com',
            [RecordType::A, RecordType::RRSIG, RecordType::NSEC],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('Wildcard');

        NSECProofValidator::validateNxdomain('beta.example.com', [$nsec1, $nsecWildcard]);
    }

    public function testNsecNxdomainThrowsWhenWildcardNotCovered(): void
    {
        $nsec = new NSECRecord(
            'alpha.example.com',
            Duration::seconds(3600),
            'gamma.example.com',
            [RecordType::A, RecordType::RRSIG, RecordType::NSEC],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No NSEC record covers the wildcard');

        NSECProofValidator::validateNxdomain('beta.example.com', [$nsec]);
    }

    public function testDsNonExistenceWithNsecProof(): void
    {
        $nsec = new NSECRecord('example.com', Duration::seconds(3600), 'mail.example.com', [
            RecordType::A,
            RecordType::NS,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec]);

        static::assertTrue(true);
    }

    public function testDsNonExistenceThrowsWhenDsTypePresent(): void
    {
        $nsec = new NSECRecord(
            'example.com',
            Duration::seconds(3600),
            'mail.example.com',
            [
                RecordType::A,
                RecordType::DS,
                RecordType::SOA,
            ],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('includes type DS');

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec]);
    }

    public function testDsNonExistenceThrowsWhenNoRecords(): void
    {
        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No NSEC or NSEC3 records found for DS non-existence');

        NSECProofValidator::validateDsNonExistence('example.com', []);
    }

    public function testDsNonExistenceThrowsWhenNsecNameNotMatched(): void
    {
        $nsec = new NSECRecord(
            'other.example.com',
            Duration::seconds(3600),
            'zzz.example.com',
            [
                RecordType::A,
                RecordType::SOA,
            ],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No NSEC record matches');

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec]);
    }

    public function testNsec3DsNonExistenceWithExactMatch(): void
    {
        $salt = '';
        $iterations = 0;
        $algorithm = 1;

        $hash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute('example.com', $algorithm, $iterations, $salt)));

        $nsec3 = new NSEC3Record(
            $hash . '.example.com',
            Duration::seconds(3600),
            $algorithm,
            0,
            $iterations,
            $salt,
            self::nextHash($hash),
            [RecordType::A, RecordType::NS],
        );

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec3]);

        static::assertTrue(true);
    }

    public function testNsec3DsNonExistenceThrowsWhenDsInBitmap(): void
    {
        $salt = '';
        $iterations = 0;
        $algorithm = 1;

        $hash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute('example.com', $algorithm, $iterations, $salt)));

        $nsec3 = new NSEC3Record(
            $hash . '.example.com',
            Duration::seconds(3600),
            $algorithm,
            0,
            $iterations,
            $salt,
            self::nextHash($hash),
            [RecordType::A, RecordType::DS],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('includes type DS');

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec3]);
    }

    public function testNsec3DsNonExistenceWithOptOut(): void
    {
        $salt = '';
        $iterations = 0;
        $algorithm = 1;

        $hash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute('example.com', $algorithm, $iterations, $salt)));

        $nsec3 = self::coveringNsec3WithOptOut($hash, $algorithm, $iterations, $salt);

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec3]);

        static::assertTrue(true);
    }

    public function testNsec3DsNonExistenceThrowsWhenNotProven(): void
    {
        $salt = '';
        $iterations = 0;
        $algorithm = 1;

        $hash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute('example.com', $algorithm, $iterations, $salt)));

        $nsec3 = new NSEC3Record(
            'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ.example.com',
            Duration::seconds(3600),
            $algorithm,
            0,
            $iterations,
            $salt,
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA1',
            [RecordType::A],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No NSEC3 proof of DS non-existence');

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec3]);
    }

    public function testNsec3NxdomainWithInconsistentParametersThrows(): void
    {
        $nsec1 = new NSEC3Record(
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA00.example.com',
            Duration::seconds(3600),
            1,
            0,
            0,
            '',
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA01',
            [RecordType::A],
        );

        $nsec2 = new NSEC3Record(
            'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB00.example.com',
            Duration::seconds(3600),
            1,
            0,
            5,
            '',
            'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB01',
            [RecordType::A],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('inconsistent');

        NSECProofValidator::validateNxdomain('test.example.com', [$nsec1, $nsec2]);
    }

    public function testNsec3NodataWithInconsistentParametersThrows(): void
    {
        $nsec1 = new NSEC3Record(
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA00.example.com',
            Duration::seconds(3600),
            1,
            0,
            0,
            'aabb',
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA01',
            [RecordType::A],
        );

        $nsec2 = new NSEC3Record(
            'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB00.example.com',
            Duration::seconds(3600),
            1,
            0,
            0,
            'ccdd',
            'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB01',
            [RecordType::A],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('inconsistent');

        NSECProofValidator::validateNodata('example.com', RecordType::AAAA, [$nsec1, $nsec2]);
    }

    public function testNsec3NodataThrowsWhenNameNotMatched(): void
    {
        $nsec3 = new NSEC3Record(
            'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ.example.com',
            Duration::seconds(3600),
            1,
            0,
            0,
            '',
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA1',
            [RecordType::A],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No NSEC3 record matches');

        NSECProofValidator::validateNodata('example.com', RecordType::AAAA, [$nsec3]);
    }

    public function testNsec3NxdomainThrowsWhenNoClosestEncloser(): void
    {
        $nsec3 = new NSEC3Record(
            'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ.example.com',
            Duration::seconds(3600),
            1,
            0,
            0,
            '',
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA1',
            [RecordType::A],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No closest encloser');

        NSECProofValidator::validateNxdomain('nonexistent.example.com', [$nsec3]);
    }

    public function testNsec3NxdomainThrowsWhenWildcardNotCovered(): void
    {
        $salt = 'aabb';
        $iterations = 0;
        $algorithm = 1;

        $closestEncloser = 'example.com';
        $nextCloser = 'nonexistent.example.com';

        $ceHash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute(
            $closestEncloser,
            $algorithm,
            $iterations,
            $salt,
        )));

        $ncHash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute($nextCloser, $algorithm, $iterations, $salt)));

        $nsec3Ce = new NSEC3Record(
            $ceHash . '.example.com',
            Duration::seconds(3600),
            $algorithm,
            0,
            $iterations,
            $salt,
            self::nextHash($ceHash),
            [RecordType::A, RecordType::SOA],
        );

        $nsec3Nc = self::coveringNsec3($ncHash, $algorithm, $iterations, $salt);

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('wildcard');

        NSECProofValidator::validateNxdomain('nonexistent.example.com', [$nsec3Ce, $nsec3Nc]);
    }

    public function testNsec3NxdomainWithOptOutReturnsEarly(): void
    {
        $salt = 'aabb';
        $iterations = 0;
        $algorithm = 1;

        $closestEncloser = 'example.com';
        $nextCloser = 'nonexistent.example.com';

        $ceHash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute(
            $closestEncloser,
            $algorithm,
            $iterations,
            $salt,
        )));
        $ncHash = Byte\uppercase(Base32Hex::encode(NSEC3Hash::compute($nextCloser, $algorithm, $iterations, $salt)));

        $nsec3Ce = new NSEC3Record(
            $ceHash . '.example.com',
            Duration::seconds(3600),
            $algorithm,
            0,
            $iterations,
            $salt,
            self::nextHash($ceHash),
            [RecordType::A, RecordType::SOA],
        );

        $nsec3Nc = self::coveringNsec3WithOptOut($ncHash, $algorithm, $iterations, $salt);

        NSECProofValidator::validateNxdomain('nonexistent.example.com', [$nsec3Ce, $nsec3Nc]);

        static::assertTrue(true);
    }

    public function testNsec3DsNonExistenceWithInconsistentParametersThrows(): void
    {
        $nsec1 = new NSEC3Record(
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA00.example.com',
            Duration::seconds(3600),
            1,
            0,
            0,
            '',
            'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA01',
            [RecordType::A],
        );

        $nsec2 = new NSEC3Record(
            'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB00.example.com',
            Duration::seconds(3600),
            1,
            0,
            10,
            '',
            'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB01',
            [RecordType::A],
        );

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('inconsistent');

        NSECProofValidator::validateDsNonExistence('example.com', [$nsec1, $nsec2]);
    }

    private static function nextHash(string $hash): string
    {
        $bytes = Base32Hex::decode($hash);
        $len = Byte\length($bytes);
        /** @var non-negative-int $prefixLen */
        $prefixLen = $len - 1;
        $last = Byte\ord($bytes[$prefixLen]);
        $incremented = Byte\slice($bytes, 0, $prefixLen) . Byte\chr(($last + 1) % 256);

        return Base32Hex::encode($incremented);
    }

    private static function coveringNsec3(
        string $targetHash,
        int $algorithm,
        int $iterations,
        string $salt,
    ): NSEC3Record {
        $targetBytes = Base32Hex::decode($targetHash);
        $len = Byte\length($targetBytes);
        /** @var non-negative-int $prefixLen */
        $prefixLen = $len - 1;
        $last = Byte\ord($targetBytes[$prefixLen]);

        $beforeByte = ($last - 1 + 256) % 256;
        $afterByte = ($last + 1) % 256;

        $beforeBytes = Byte\slice($targetBytes, 0, $prefixLen) . Byte\chr($beforeByte);
        $afterBytes = Byte\slice($targetBytes, 0, $prefixLen) . Byte\chr($afterByte);

        $ownerHash = Byte\uppercase(Base32Hex::encode($beforeBytes));
        $nextHash = Byte\uppercase(Base32Hex::encode($afterBytes));

        return new NSEC3Record(
            $ownerHash . '.example.com',
            Duration::seconds(3600),
            $algorithm,
            0,
            $iterations,
            $salt,
            $nextHash,
            [RecordType::RRSIG],
        );
    }

    private static function coveringNsec3WithOptOut(
        string $targetHash,
        int $algorithm,
        int $iterations,
        string $salt,
    ): NSEC3Record {
        $targetBytes = Base32Hex::decode($targetHash);
        $len = Byte\length($targetBytes);
        /** @var non-negative-int $prefixLen */
        $prefixLen = $len - 1;
        $last = Byte\ord($targetBytes[$prefixLen]);

        $beforeByte = ($last - 1 + 256) % 256;
        $afterByte = ($last + 1) % 256;

        $beforeBytes = Byte\slice($targetBytes, 0, $prefixLen) . Byte\chr($beforeByte);
        $afterBytes = Byte\slice($targetBytes, 0, $prefixLen) . Byte\chr($afterByte);

        $ownerHash = Byte\uppercase(Base32Hex::encode($beforeBytes));
        $nextHash = Byte\uppercase(Base32Hex::encode($afterBytes));

        return new NSEC3Record(
            $ownerHash . '.example.com',
            Duration::seconds(3600),
            $algorithm,
            1,
            $iterations,
            $salt,
            $nextHash,
            [RecordType::RRSIG],
        );
    }
}
