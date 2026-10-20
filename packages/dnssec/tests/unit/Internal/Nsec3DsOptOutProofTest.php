<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\Internal\Base32Hex;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Internal\NSEC\NSEC3Hash;
use Psl\DNSSEC\Internal\NSEC\NSEC3ProofValidator;

use function chr;
use function ord;
use function substr;

final class Nsec3DsOptOutProofTest extends TestCase
{
    public function testCompleteOptOutProof(): void
    {
        $records = [self::matching('example.com'), self::covering('unsigned.example.com')];

        static::assertTrue(NSEC3ProofValidator::validateDsNonExistence('unsigned.example.com', $records));
        static::assertTrue(NSEC3ProofValidator::validateDsNonExistence('leaf.unsigned.example.com', $records));
    }

    /** @param list<NSEC3Record> $records */
    #[DataProvider('invalidProofs')]
    public function testRejectsIncompleteOrInvalidProof(array $records): void
    {
        $this->expectException(InvalidProofException::class);

        NSEC3ProofValidator::validateDsNonExistence('leaf.unsigned.example.com', $records);
    }

    /** @return iterable<string, array{list<NSEC3Record>}> */
    public static function invalidProofs(): iterable
    {
        yield 'no closest encloser' => [[self::covering('leaf.unsigned.example.com')]];
        yield 'no next closer cover' => [[self::matching('example.com')]];
        yield 'query cover is not next closer cover' => [[
            self::matching('example.com'),
            self::covering('leaf.unsigned.example.com'),
        ]];
        yield 'next closer has no opt-out flag' => [[
            self::matching('example.com'),
            self::covering('unsigned.example.com', 0),
        ]];
        yield 'closest encloser is a DNAME' => [[
            self::matching('example.com', [RecordType::DNAME]),
            self::covering('unsigned.example.com'),
        ]];
        yield 'closest encloser is a delegation' => [[
            self::matching('example.com', [RecordType::NS]),
            self::covering('unsigned.example.com'),
        ]];
        yield 'closer match prevents use of ancestor proof' => [[
            self::matching('example.com'),
            self::matching('unsigned.example.com'),
            self::covering('unsigned.example.com'),
        ]];
    }

    /** @param list<RecordType> $types */
    private static function matching(string $name, array $types = [RecordType::NS, RecordType::SOA]): NSEC3Record
    {
        $hash = NSEC3Hash::compute($name, 1, 0, '');
        $next = substr($hash, 0, -1) . chr((ord($hash[19]) + 1) % 256);

        return new NSEC3Record(
            Base32Hex::encode($hash) . '.example.com',
            Duration::seconds(60),
            1,
            0,
            0,
            '',
            Base32Hex::encode($next),
            $types,
        );
    }

    private static function covering(string $name, int $flags = 1): NSEC3Record
    {
        $hash = NSEC3Hash::compute($name, 1, 0, '');
        $before = substr($hash, 0, -1) . chr((ord($hash[19]) + 255) % 256);
        $after = substr($hash, 0, -1) . chr((ord($hash[19]) + 1) % 256);

        return new NSEC3Record(
            Base32Hex::encode($before) . '.example.com',
            Duration::seconds(60),
            1,
            $flags,
            0,
            '',
            Base32Hex::encode($after),
            [RecordType::A],
        );
    }
}
