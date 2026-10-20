<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Internal\Base32Hex;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\NSEC\NSEC3Hash;
use Psl\DNSSEC\Internal\NSEC\NSECProofValidator;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\DNSSEC\TrustAnchor;
use Psl\DNSSEC\TrustChainResolver;
use Psl\DNSSEC\TrustChainStatus;
use ReflectionMethod;

use function hash;
use function pack;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function substr_count;
use function time;

final class DsDenialScopeTest extends TestCase
{
    public function testRejectsGrandparentOptOutNsec3Replay(): void
    {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $comPair = sodium_crypto_sign_keypair();
        $com = self::key('com', $comPair);
        $exPair = sodium_crypto_sign_keypair();
        $ex = self::key('example.com', $exPair);
        $subPair = sodium_crypto_sign_keypair();
        $sub = self::key('sub.example.com', $subPair);

        $comDs = self::ds($com);
        $exDs = self::ds($ex);
        $subDs = self::ds($sub);
        // A genuine record of com's opt-out NSEC3 chain (signed by com) that happens to cover H(sub.example.com).
        $hash = Base32Hex::encode(NSEC3Hash::compute('sub.example.com', 1, 0, ''));
        static::assertNotSame('00000000000000000000000000000000', $hash);
        $optOut = new NSEC3Record(
            '00000000000000000000000000000000.com',
            Duration::seconds(300),
            1,
            1,
            0,
            '',
            'VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV',
            [RecordType::NS],
        );
        $optOutSig = self::sign([$optOut], $com, $comPair);

        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturnCallback(static fn(string $name, RecordType $type): Response => match ([$name, $type]) {
                ['.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$root, self::sign([$root], $root, $rootPair)],
                    [],
                    [],
                ),
                ['com.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$comDs, self::sign([$comDs], $root, $rootPair)],
                    [],
                    [],
                ),
                ['com.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$com, self::sign([$com], $com, $comPair)],
                    [],
                    [],
                ),
                ['example.com.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$exDs, self::sign([$exDs], $com, $comPair)],
                    [],
                    [],
                ),
                ['example.com.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$ex, self::sign([$ex], $ex, $exPair)],
                    [],
                    [],
                ),
                // Honest answer would be [$subDs, RRSIG by example.com]; the attacker replays com's NSEC3 instead.
                ['sub.example.com.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [],
                    [$optOut, $optOutSig],
                    [],
                ),
                default => throw new LogicException($name . ' ' . $type->name),
            });

        $result = new TrustChainResolver($inner, new TrustAnchor([self::ds($root)]))->resolve('sub.example.com');
        static::assertSame(TrustChainStatus::Bogus, $result->status);
        unset($subDs);
    }

    /** @return iterable<string, array{string, string, TrustChainStatus}> */
    public static function proofZones(): iterable
    {
        yield 'parent proof' => ['example.com', 'example.com', TrustChainStatus::Insecure];
        yield 'grandparent signer' => ['com', 'com', TrustChainStatus::Bogus];
        yield 'NSEC3 owner below wrong zone' => ['example.com', 'branch.example.com', TrustChainStatus::Bogus];
    }

    #[DataProvider('proofZones')]
    public function testCompleteOptOutProofUsesParentZone(
        string $signer,
        string $ownerZone,
        TrustChainStatus $expected,
    ): void {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $comPair = sodium_crypto_sign_keypair();
        $com = self::key('com', $comPair);
        $exPair = sodium_crypto_sign_keypair();
        $ex = self::key('example.com', $exPair);
        $comDs = self::ds($com);
        $exDs = self::ds($ex);
        $closest = new NSEC3Record(
            Base32Hex::encode(NSEC3Hash::compute('example.com', 1, 0, '')) . '.' . $ownerZone,
            Duration::seconds(300),
            1,
            0,
            0,
            '',
            'VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV',
            [RecordType::SOA, RecordType::NS],
        );
        $covering = new NSEC3Record(
            '00000000000000000000000000000000.' . $ownerZone,
            Duration::seconds(300),
            1,
            1,
            0,
            '',
            'VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV',
            [RecordType::A],
        );
        $key = $signer === 'com' ? $com : $ex;
        $pair = $signer === 'com' ? $comPair : $exPair;
        $authority = [$closest, self::sign([$closest], $key, $pair), $covering, self::sign([$covering], $key, $pair)];
        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturnCallback(static fn(string $name, RecordType $type): Response => match ([$name, $type]) {
                ['.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$root, self::sign([$root], $root, $rootPair)],
                    [],
                    [],
                ),
                ['com.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$comDs, self::sign([$comDs], $root, $rootPair)],
                    [],
                    [],
                ),
                ['com.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$com, self::sign([$com], $com, $comPair)],
                    [],
                    [],
                ),
                ['example.com.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$exDs, self::sign([$exDs], $com, $comPair)],
                    [],
                    [],
                ),
                ['example.com.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$ex, self::sign([$ex], $ex, $exPair)],
                    [],
                    [],
                ),
                ['sub.example.com.', RecordType::DS] => new Response(1, ResponseCode::NoError, [], $authority, []),
                default => throw new LogicException($name . ' ' . $type->name),
            });

        static::assertSame(
            $expected,
            new TrustChainResolver($inner, new TrustAnchor([self::ds($root)]))->resolve('sub.example.com')->status,
        );
    }

    public function testRejectsCoveringNsecAtAncestorDelegation(): void
    {
        $nsec = new NSECRecord('branch.example', Duration::seconds(300), 'z.branch.example', [RecordType::NS]);

        $this->expectException(InvalidProofException::class);
        NSECProofValidator::validateDsNonExistence('leaf.branch.example', [$nsec]);
    }

    public function testCoveringNsecAtSiblingDelegationStillProvesAbsence(): void
    {
        $nsec = new NSECRecord('aaa.example', Duration::seconds(300), 'zzz.example', [RecordType::NS]);

        static::assertFalse(NSECProofValidator::validateDsNonExistence('leaf.example', [$nsec]));
    }

    private static function key(string $zone, string $pair, int $flags = 257): DNSKEYRecord
    {
        return new DNSKEYRecord(
            $zone,
            Duration::seconds(300),
            $flags,
            3,
            Algorithm::ED25519,
            sodium_crypto_sign_publickey($pair),
        );
    }

    private static function ds(DNSKEYRecord $key): DSRecord
    {
        return new DSRecord(
            $key->name,
            Duration::seconds(300),
            KeyTag::compute($key),
            $key->algorithm,
            DigestAlgorithm::SHA256,
            hash(
                'sha256',
                Encoder::encodeName($key->name) . pack('nCC', $key->flags, 3, $key->algorithm->value) . $key->publicKey,
            ),
        );
    }

    /** @param non-empty-list<RecordInterface> $rrset */
    private static function sign(array $rrset, DNSKEYRecord $key, string $pair): RRSIGRecord
    {
        $now = time();
        $unsigned = new RRSIGRecord(
            $rrset[0]->name,
            Duration::seconds(300),
            $rrset[0]->kind,
            Algorithm::ED25519,
            $rrset[0]->name === '.' ? 0 : substr_count($rrset[0]->name, '.') + 1,
            300,
            $now + 3600,
            $now - 3600,
            KeyTag::compute($key),
            $key->name,
            '',
        );
        $data = new ReflectionMethod(RRSIGVerifier::class, 'buildSignedData')->invoke(null, $unsigned, $rrset);

        return new RRSIGRecord(
            $unsigned->name,
            $unsigned->duration,
            $unsigned->typeCovered,
            $unsigned->algorithm,
            $unsigned->labels,
            $unsigned->originalTtl,
            $unsigned->expiration,
            $unsigned->inception,
            $unsigned->keyTag,
            $unsigned->signer,
            sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($pair)),
        );
    }
}
