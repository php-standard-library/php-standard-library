<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Internal\KeyTag;
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
use function time;

final class AuthenticatedDnskeySignerTest extends TestCase
{
    #[DataProvider('signers')]
    public function testOnlyAuthenticatedKeyMaySignDnskeySet(
        string $zone,
        bool $otherSigns,
        bool $otherKeyTrusted = false,
    ): void {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $genuinePair = $zone === '.' ? $rootPair : sodium_crypto_sign_keypair();
        $genuine = self::key($zone, $genuinePair);
        $otherPair = sodium_crypto_sign_keypair();
        $other = self::key($zone, $otherPair, $otherKeyTrusted ? 256 : 257);
        $rrset = [$genuine, $other];
        $signature = self::sign($rrset, $otherSigns ? $other : $genuine, $otherSigns ? $otherPair : $genuinePair);
        static::assertTrue(RRSIGVerifier::verify($signature, $otherSigns ? $other : $genuine, $rrset));

        $dsRecords = $otherKeyTrusted ? [self::ds($genuine), self::ds($other)] : [self::ds($genuine)];
        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturnCallback(static function (string $name, RecordType $type) use (
                $zone,
                $root,
                $rootPair,
                $dsRecords,
                $rrset,
                $signature,
            ): Response {
                $answers = match (true) {
                    $type === RecordType::DS => [...$dsRecords, self::sign($dsRecords, $root, $rootPair)],
                    $name === '.' && $zone !== '.' => [$root, self::sign([$root], $root, $rootPair)],
                    default => [...$rrset, $signature],
                };
                return new Response(1, ResponseCode::NoError, $answers, [], []);
            });
        $anchor = new TrustAnchor($zone === '.' ? $dsRecords : [self::ds($root)]);
        $result = new TrustChainResolver($inner, $anchor)->resolve($zone);

        static::assertSame(
            $otherSigns && !$otherKeyTrusted ? TrustChainStatus::Bogus : TrustChainStatus::Secure,
            $result->status,
        );
        if (!$otherSigns || $otherKeyTrusted) {
            static::assertSame($rrset, $result->keys);
        }
    }

    public static function signers(): iterable
    {
        yield 'root attacker' => ['.', true];
        yield 'root authenticated signer' => ['.', false];
        yield 'child attacker' => ['example', true];
        yield 'child authenticated signer' => ['example', false];
        yield 'root DS-matched signer without SEP' => ['.', true, true];
        yield 'child DS-matched signer without SEP' => ['example', true, true];
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
            $rrset[0]->name === '.' ? 0 : 1,
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
