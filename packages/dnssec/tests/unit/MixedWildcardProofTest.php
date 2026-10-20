<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\SignatureFailedException;
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

final class MixedWildcardProofTest extends TestCase
{
    public function testWildcardCnameAndTerminalNxdomainUseTheirOwnProofs(): void
    {
        $sourcePair = sodium_crypto_sign_keypair();
        $source = self::key('example', $sourcePair);
        $targetPair = sodium_crypto_sign_keypair();
        $target = self::key('other', $targetPair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'missing.other');
        $sourceProof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $targetProof = new NSECRecord('other', Duration::seconds(300), 'zzz.other', [RecordType::SOA]);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $source, $sourcePair, 1)],
            [
                $sourceProof,
                self::sign($sourceProof, $source, $sourcePair),
                $targetProof,
                self::sign($targetProof, $target, $targetPair),
            ],
            [$source, $target],
            ResponseCode::NonExistentDomain,
        );

        $response = $resolver->query($alias->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame(ResponseCode::NonExistentDomain, $response->code);
    }

    public function testInvalidSourceSignatureDoesNotHideValidTargetProof(): void
    {
        $sourcePair = sodium_crypto_sign_keypair();
        $source = self::key('example', $sourcePair);
        $targetPair = sodium_crypto_sign_keypair();
        $target = self::key('other', $targetPair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'missing.other');
        $sourceProof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $targetProof = new NSECRecord('other', Duration::seconds(300), 'zzz.other', [RecordType::SOA]);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $source, $sourcePair, 1)],
            [
                $sourceProof,
                self::sign($sourceProof, $source, $sourcePair),
                $targetProof,
                self::sign($targetProof, $source, $targetPair),
                self::sign($targetProof, $target, $targetPair),
            ],
            [$source, $target],
            ResponseCode::NonExistentDomain,
        );

        $response = $resolver->query($alias->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame(ResponseCode::NonExistentDomain, $response->code);
    }

    public function testCrossZoneWildcardCnameAndWildcardAnswerUseTheirOwnProofs(): void
    {
        $sourcePair = sodium_crypto_sign_keypair();
        $source = self::key('example', $sourcePair);
        $targetPair = sodium_crypto_sign_keypair();
        $target = self::key('other', $targetPair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'www.other');
        $answer = new ARecord('www.other', Duration::seconds(300), Address::v4('192.0.2.1'));
        $sourceProof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $targetProof = new NSECRecord('other', Duration::seconds(300), 'zzz.other', [RecordType::SOA]);
        $resolver = $this->resolver(
            [
                $alias,
                self::sign($alias, $source, $sourcePair, 1),
                $answer,
                self::sign($answer, $target, $targetPair, 1),
            ],
            [
                $sourceProof,
                self::sign($sourceProof, $source, $sourcePair),
                $targetProof,
                self::sign($targetProof, $target, $targetPair),
            ],
            [$source, $target],
        );

        $response = $resolver->query($alias->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertContains($answer, $response->answers);
    }

    public function testWildcardCnameOnlyResponseDoesNotDenyTarget(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $target = self::key('other', sodium_crypto_sign_keypair());
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'www.other');
        $proof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $key, $pair, 1)],
            [$proof, self::sign($proof, $key, $pair)],
            [$key, $target],
        );

        $response = $resolver->query($alias->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertContains($alias, $response->answers);
    }

    public function testWildcardCnameOnlyResponseDoesNotNeedTargetKeys(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'www.other');
        $proof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $key, $pair, 1)],
            [$proof, self::sign($proof, $key, $pair)],
            [$key],
        );

        $response = $resolver->query($alias->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame(ResponseCode::NoError, $response->code);
    }

    public function testWildcardProofCannotAuthenticateUnsignedTerminalProof(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('example', $pair);
        $target = self::key('other', sodium_crypto_sign_keypair());
        $alias = new CNAMERecord('www.example', Duration::seconds(300), 'www.other');
        $sourceProof = new NSECRecord('example', Duration::seconds(300), 'zzz.example', [RecordType::SOA]);
        $unsignedProof = new NSECRecord('other', Duration::seconds(300), 'zzz.other', [RecordType::SOA]);
        $resolver = $this->resolver(
            [$alias, self::sign($alias, $key, $pair, 1)],
            [$sourceProof, self::sign($sourceProof, $key, $pair), $unsignedProof],
            [$key, $target],
        );

        $this->expectException(SignatureFailedException::class);
        $resolver->query($alias->name, RecordType::A);
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
