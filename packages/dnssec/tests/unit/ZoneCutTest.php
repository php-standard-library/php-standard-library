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
use Psl\DNS\Record\ARecord;
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
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\TrustAnchor;
use Psl\DNSSEC\TrustChainResolver;
use Psl\DNSSEC\TrustChainStatus;
use Psl\IP\Address;
use ReflectionMethod;

use function hash;
use function pack;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function substr_count;
use function time;

final class ZoneCutTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function proofKinds(): iterable
    {
        yield 'NSEC' => [false];
        yield 'NSEC3' => [true];
    }

    #[DataProvider('proofKinds')]
    public function testDsNodataAtHostKeepsParentKeys(bool $nsec3): void
    {
        [$chain, $parent] = $this->chain($nsec3, [RecordType::A]);
        $result = $chain->resolve('branch.example');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertSame([$parent], $result->keys);
    }

    #[DataProvider('proofKinds')]
    public function testStrippedSignatureCannotDowngradeHost(bool $nsec3): void
    {
        [$chain, , , $inner] = $this->chain($nsec3, [RecordType::A]);
        $resolver = new SecureResolver($inner, $chain);

        $this->expectException(InvalidProofException::class);
        $resolver->query('branch.example', RecordType::A);
    }

    #[DataProvider('proofKinds')]
    public function testUnsignedDelegationIsInsecure(bool $nsec3): void
    {
        [$chain] = $this->chain($nsec3, [RecordType::NS]);

        static::assertSame(TrustChainStatus::Insecure, $chain->resolve('branch.example')->status);
    }

    #[DataProvider('proofKinds')]
    public function testChildApexProofCannotEstablishUnsignedDelegation(bool $nsec3): void
    {
        [$chain] = $this->chain($nsec3, [RecordType::NS, RecordType::SOA]);

        static::assertSame(TrustChainStatus::Bogus, $chain->resolve('branch.example')->status);
    }

    #[DataProvider('proofKinds')]
    public function testCnameProofCannotEstablishDsNodata(bool $nsec3): void
    {
        [$chain] = $this->chain($nsec3, [RecordType::CNAME]);

        static::assertSame(TrustChainStatus::Bogus, $chain->resolve('branch.example')->status);
    }

    #[DataProvider('proofKinds')]
    public function testEmptyNonterminalKeepsParentKeys(bool $nsec3): void
    {
        [$chain, $parent] = $this->chain($nsec3, [], true);
        $result = $chain->resolve('branch.example');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertSame([$parent], $result->keys);
    }

    #[DataProvider('proofKinds')]
    public function testChainContinuesBelowEmptyNonterminal(bool $nsec3): void
    {
        [$chain, , $child] = $this->chain($nsec3, [], true);
        $result = $chain->resolve('signed.branch.example');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertSame([$child], $result->keys);
    }

    /**
     * @param list<RecordType> $types
     * @return array{TrustChainResolver, DNSKEYRecord, DNSKEYRecord, ResolverInterface}
     */
    private function chain(bool $nsec3, array $types, bool $emptyNonterminal = false): array
    {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $parentPair = sodium_crypto_sign_keypair();
        $parent = self::key('example', $parentPair);
        $childPair = sodium_crypto_sign_keypair();
        $child = self::key('signed.branch.example', $childPair);
        $parentDs = self::ds($parent);
        $childDs = self::ds($child);
        $proof = $nsec3
            ? new NSEC3Record(
                Base32Hex::encode(NSEC3Hash::compute('branch.example', 1, 0, '')) . '.example',
                Duration::seconds(300),
                1,
                0,
                0,
                '',
                'VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV',
                $types,
            )
            : new NSECRecord(
                $emptyNonterminal ? 'example' : 'branch.example',
                Duration::seconds(300),
                'leaf.branch.example',
                $types,
            );
        $authority = [$proof, self::sign([$proof], $parent, $parentPair)];
        $rootResponse = new Response(1, ResponseCode::NoError, [$root, self::sign([$root], $root, $rootPair)], [], []);
        $parentResponse = new Response(
            1,
            ResponseCode::NoError,
            [$parent, self::sign([$parent], $parent, $parentPair)],
            [],
            [],
        );
        $childResponse = new Response(
            1,
            ResponseCode::NoError,
            [$child, self::sign([$child], $child, $childPair)],
            [],
            [],
        );
        $parentDsResponse = new Response(
            1,
            ResponseCode::NoError,
            [$parentDs, self::sign([$parentDs], $root, $rootPair)],
            [],
            [],
        );
        $childDsResponse = new Response(
            1,
            ResponseCode::NoError,
            [$childDs, self::sign([$childDs], $parent, $parentPair)],
            [],
            [],
        );
        $proofResponse = new Response(1, ResponseCode::NoError, [], $authority, []);
        $answer = new Response(
            1,
            ResponseCode::NoError,
            [new ARecord('branch.example', Duration::seconds(300), Address::v4('192.0.2.1'))],
            [],
            [],
        );
        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturnCallback(static fn(string $name, RecordType $type): Response => match ([$name, $type]) {
                ['.', RecordType::DNSKEY] => $rootResponse,
                ['example.', RecordType::DNSKEY] => $parentResponse,
                ['signed.branch.example.', RecordType::DNSKEY] => $childResponse,
                ['example.', RecordType::DS] => $parentDsResponse,
                ['branch.example.', RecordType::DS] => $proofResponse,
                ['signed.branch.example.', RecordType::DS] => $childDsResponse,
                ['branch.example', RecordType::A] => $answer,
                default => throw new LogicException('Unexpected query: ' . $name . ' ' . $type->name),
            });

        return [new TrustChainResolver($inner, new TrustAnchor([self::ds($root)])), $parent, $child, $inner];
    }

    private static function key(string $zone, string $pair): DNSKEYRecord
    {
        return new DNSKEYRecord(
            $zone,
            Duration::seconds(300),
            257,
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
