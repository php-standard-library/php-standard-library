<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Internal\Base32Hex;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\NSEC3Record;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\Record\SOARecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\NSEC\NSEC3Hash;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\StaticTrustChainResolver;
use Psl\DNSSEC\TrustChainResolverInterface;
use Psl\DNSSEC\TrustChainResult;
use Psl\DNSSEC\TrustChainStatus;
use Psl\IP\Address;
use ReflectionMethod;

use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function str_repeat;
use function substr_count;
use function time;

final class AuthorityCoverageTest extends TestCase
{
    public function testUnsignedNsecCannotBorrowSoaSignature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $soa = self::soa();
        $proof = self::nsec();
        $resolver = $this->resolver([], [$proof, $soa, self::sign($soa, $key, $pair)], [$key]);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.example', RecordType::A);
    }

    public function testUnsignedSoaCannotBorrowNsecSignature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $proof = self::nsec();
        $resolver = $this->resolver([], [$proof, self::sign($proof, $key, $pair), self::soa()], [$key]);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.example', RecordType::A);
    }

    public function testUnsignedNsec3CannotBorrowSoaSignature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $soa = self::soa();
        $hash = Base32Hex::encode(NSEC3Hash::compute('www.example', 1, 0, ''));
        $proof = new NSEC3Record(
            $hash . '.example',
            Duration::seconds(300),
            1,
            0,
            0,
            '',
            'VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV',
            [],
        );
        $resolver = $this->resolver([], [$proof, $soa, self::sign($soa, $key, $pair)], [$key], ResponseCode::NoError);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.example', RecordType::A);
    }

    public function testPositiveAnswerRequiresSignedAuthorityProofs(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $answer = new ARecord('www.example', Duration::seconds(300), Address::v4('192.0.2.1'));
        $resolver = $this->resolver(
            [$answer, self::sign($answer, $key, $pair)],
            [self::soa()],
            [$key],
            ResponseCode::NoError,
        );

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.example', RecordType::A);
    }

    public function testParentProofCannotDenyANameInSecureChild(): void
    {
        $parentPair = sodium_crypto_sign_keypair();
        $parent = self::key('example', $parentPair);
        $child = self::key('child.example', sodium_crypto_sign_keypair());
        $proof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $resolver = $this->resolver([], [$proof, self::sign($proof, $parent, $parentPair)], [$parent, $child]);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.child.example', RecordType::A);
    }

    public function testEveryAuthorityRrsetHasAValidSignature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $proof = self::nsec();
        $soa = self::soa();
        $authority = [$proof, self::sign($proof, $key, $pair), $soa, self::sign($soa, $key, $pair)];
        $resolver = $this->resolver([], $authority, [$key]);

        static::assertTrue($resolver->query('www.example', RecordType::A)->authenticatedData);
    }

    public function testValidSignatureCanFollowAnInvalidSignature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $other = self::key('example', sodium_crypto_sign_keypair());
        $proof = self::nsec();
        $resolver = $this->resolver(
            [],
            [$proof, self::sign($proof, $other, $pair), self::sign($proof, $key, $pair)],
            [$key],
        );

        static::assertTrue($resolver->query('www.example', RecordType::A)->authenticatedData);
    }

    public function testP6ForgedNxdomainWithUnsignedNsec(): void
    {
        $victimPair = sodium_crypto_sign_keypair();
        $victim = self::key('victim.example', $victimPair);
        $n1 = new NSECRecord(
            'victim.example',
            Duration::seconds(300),
            'zzz.victim.example',
            [RecordType::SOA, RecordType::NS],
        );
        $junkSig = new RRSIGRecord(
            'victim.example',
            Duration::seconds(300),
            RecordType::SOA,
            Algorithm::ED25519,
            2,
            300,
            time() + 3600,
            time() - 3600,
            KeyTag::compute($victim),
            'victim.example',
            str_repeat("\0", 64),
        );
        $inner = $this->createStub(ResolverInterface::class);
        $inner->method('query')->willReturn(new Response(1, ResponseCode::NonExistentDomain, [], [$n1, $junkSig], []));
        $chain = $this->createStub(TrustChainResolverInterface::class);
        $chain->method('resolve')->willReturn(new TrustChainResult(TrustChainStatus::Secure, [$victim]));

        $this->expectException(SignatureFailedException::class);
        new SecureResolver($inner, $chain)->query('www.victim.example', RecordType::A);
    }

    private static function nsec(): NSECRecord
    {
        return new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA, RecordType::NS]);
    }

    private static function soa(): SOARecord
    {
        return new SOARecord(
            'example',
            Duration::seconds(300),
            'ns.example',
            'admin.example',
            1,
            Duration::seconds(60),
            Duration::seconds(60),
            Duration::seconds(600),
            Duration::seconds(60),
        );
    }

    /**
     * @param list<RecordInterface> $answers
     * @param list<RecordInterface> $authority
     * @param list<DNSKEYRecord> $keys
     */
    private function resolver(
        array $answers,
        array $authority,
        array $keys,
        ResponseCode $code = ResponseCode::NonExistentDomain,
    ): SecureResolver {
        $inner = $this->createStub(ResolverInterface::class);
        $inner->method('query')->willReturn(new Response(1, $code, $answers, $authority, [], authenticatedData: true));
        $zones = [];
        foreach ($keys as $key) {
            $zones[$key->name][] = $key;
        }

        return new SecureResolver($inner, new StaticTrustChainResolver($zones));
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

    private static function sign(RecordInterface $record, DNSKEYRecord $key, string $pair): RRSIGRecord
    {
        $unsigned = new RRSIGRecord(
            $record->name,
            $record->duration,
            $record->kind,
            $key->algorithm,
            substr_count($record->name, '.') + 1,
            300,
            2_000_000_000,
            1_000_000_000,
            KeyTag::compute($key),
            $key->name,
            '',
        );
        $data = new ReflectionMethod(RRSIGVerifier::class, 'buildSignedData')->invoke(null, $unsigned, [$record]);

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
