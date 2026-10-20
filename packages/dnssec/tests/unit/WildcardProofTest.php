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
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\NSEC\NSEC3Hash;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\StaticTrustChainResolver;
use Psl\IP\Address;
use ReflectionMethod;

use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function substr_count;

final class WildcardProofTest extends TestCase
{
    public function testWildcardAnswerRequiresDenialProof(): void
    {
        $resolver = $this->wildcard([]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testSignedUnrelatedNsecCannotProveExpansion(): void
    {
        $proof = new NSECRecord('x.example', Duration::seconds(300), 'z.example', []);
        $resolver = $this->wildcard([$proof]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testNsecMustDenyNextCloserName(): void
    {
        $proof = new NSECRecord('branch.example', Duration::seconds(300), 'z.example', []);
        $resolver = $this->wildcard([$proof]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testNsecCannotDenyExistingEmptyNonterminal(): void
    {
        $proof = new NSECRecord('a.example', Duration::seconds(300), 'child.branch.example', []);
        $resolver = $this->wildcard([$proof]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testNsecCannotCrossAncestorDelegation(): void
    {
        $proof = new NSECRecord('branch.example', Duration::seconds(300), 'z.example', [RecordType::NS]);
        $resolver = $this->wildcard([$proof], 2);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testSignedNsecProvesWildcardExpansion(): void
    {
        $proof = new NSECRecord('a.example', Duration::seconds(300), 'z.example', []);
        static::assertTrue($this->wildcard([$proof])->query('www.branch.example', RecordType::A)->authenticatedData);
    }

    public function testSignedNsec3ProvesWildcardExpansion(): void
    {
        static::assertTrue($this->wildcard([self::nsec3()])->query(
            'www.branch.example',
            RecordType::A,
        )->authenticatedData);
    }

    public function testNsec3OptOutClearsAuthenticatedData(): void
    {
        static::assertFalse($this->wildcard([self::nsec3(1)])->query(
            'www.branch.example',
            RecordType::A,
        )->authenticatedData);
    }

    public function testNsec3MatchingNextCloserCannotProveExpansion(): void
    {
        $hash = Base32Hex::encode(NSEC3Hash::compute('branch.example', 1, 0, ''));
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
        $resolver = $this->wildcard([$proof]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testNsec3WithUnknownFlagsCannotProveExpansion(): void
    {
        $resolver = $this->wildcard([self::nsec3(2)]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    public function testLiteralWildcardQueryDoesNotNeedExpansionProof(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $answer = new ARecord('*.example', Duration::seconds(300), Address::v4('192.0.2.1'));
        $resolver = $this->resolver([$answer, self::sign($answer, $key, $pair, 1)], [], [$key]);

        static::assertTrue($resolver->query($answer->name, RecordType::A)->authenticatedData);
    }

    public function testBroaderWildcardCannotAnswerLiteralWildcardWithoutProof(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $answer = new ARecord('*.branch.example', Duration::seconds(300), Address::v4('192.0.2.1'));
        $resolver = $this->resolver([$answer, self::sign($answer, $key, $pair, 1)], [], [$key]);

        $this->expectException(InvalidProofException::class);
        $resolver->query($answer->name, RecordType::A);
    }

    public function testExactSignatureDoesNotNeedWildcardProof(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $answer = new ARecord('www.example', Duration::seconds(300), Address::v4('192.0.2.1'));
        $resolver = $this->resolver(
            [$answer, self::sign($answer, $key, $pair, 1), self::sign($answer, $key, $pair)],
            [],
            [$key],
        );

        static::assertTrue($resolver->query($answer->name, RecordType::A)->authenticatedData);
    }

    public function testTooManyLabelsCannotAuthenticateAnAnswer(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $answer = new ARecord('www.example', Duration::seconds(300), Address::v4('192.0.2.1'));
        $resolver = $this->resolver([$answer, self::sign($answer, $key, $pair, 3)], [], [$key]);

        $this->expectException(SignatureFailedException::class);
        $resolver->query($answer->name, RecordType::A);
    }

    public function testWildcardSourceMustBeWithinSigningZone(): void
    {
        $proof = new NSECRecord('a.example', Duration::seconds(300), 'z.example', []);
        $resolver = $this->wildcard([$proof], 0);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.branch.example', RecordType::A);
    }

    /** @param list<RecordInterface> $proofs */
    private function wildcard(array $proofs, int $labels = 1): SecureResolver
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $answer = new ARecord('www.branch.example', Duration::seconds(300), Address::v4('192.0.2.1'));
        $authority = [];
        foreach ($proofs as $proof) {
            $authority[] = $proof;
            $authority[] = self::sign($proof, $key, $pair);
        }

        return $this->resolver([$answer, self::sign($answer, $key, $pair, $labels)], $authority, [$key]);
    }

    private static function nsec3(int $flags = 0): NSEC3Record
    {
        return new NSEC3Record(
            '00000000000000000000000000000000.example',
            Duration::seconds(300),
            1,
            $flags,
            0,
            '',
            'VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV',
            [],
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
        ResponseCode $code = ResponseCode::NoError,
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

    private static function sign(
        RecordInterface $record,
        DNSKEYRecord $key,
        string $pair,
        null|int $labels = null,
    ): RRSIGRecord {
        $unsigned = new RRSIGRecord(
            $record->name,
            $record->duration,
            $record->kind,
            $key->algorithm,
            $labels ?? (substr_count($record->name, '.') + 1),
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
