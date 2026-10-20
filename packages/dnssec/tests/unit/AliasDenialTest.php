<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\DNAMERecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Exception\UnsignedResponseException;
use Psl\DNSSEC\Internal\KeyTag;
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

final class AliasDenialTest extends TestCase
{
    public function testSignedCnameCannotReplaceNxdomainProof(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'missing.example');
        $resolver = $this->resolver([$alias, self::sign($alias, $key, $pair)], [], [$key]);

        $this->expectException(UnsignedResponseException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testDenialMustCoverTheFinalTarget(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'absent.other');
        $otherPair = sodium_crypto_sign_keypair();
        $otherKey = self::key('other', $otherPair);
        $proof = new NSECRecord('z.other', Duration::seconds(300), 'zzz.other', []);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $key, $pair)],
            [$proof, self::sign($proof, $otherKey, $otherPair)],
            [$key, $otherKey],
        );

        $this->expectException(InvalidProofException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testCrossZoneCnameAndFinalDenialBothVerify(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'missing.other');
        $otherPair = sodium_crypto_sign_keypair();
        $otherKey = self::key('other', $otherPair);
        $proof = new NSECRecord('other', Duration::seconds(300), 'zzz.other', [RecordType::SOA]);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $key, $pair)],
            [$proof, self::sign($proof, $otherKey, $otherPair)],
            [$key, $otherKey],
        );

        $response = $resolver->query($alias->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame(ResponseCode::NonExistentDomain, $response->code);
    }

    public function testInsecureFinalTargetClearsAd(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'missing.unsigned.other');
        $resolver = $this->resolver([$alias, self::sign($alias, $key, $pair)], [], [$key]);

        static::assertFalse($resolver->query($alias->name, RecordType::A)->authenticatedData);
    }

    public function testNxdomainCannotIncludeTheFinalAnswer(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'exists.example');
        $answer = new ARecord($alias->target, Duration::seconds(300), Address::v4('192.0.2.1'));
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $key, $pair), $answer, self::sign($answer, $key, $pair)],
            [],
            [$key],
        );

        $this->expectException(InvalidProofException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testDnameCannotReplaceFinalDenialProof(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new DNAMERecord('sub.example', Duration::seconds(300), 'other.example');
        $resolver = $this->resolver([$alias, self::sign($alias, $key, $pair)], [], [$key]);

        $this->expectException(UnsignedResponseException::class);
        $resolver->query('www.sub.example', RecordType::A);
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

        return new SecureResolver($inner, new StaticTrustChainResolver($zones, ['unsigned.other']));
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
