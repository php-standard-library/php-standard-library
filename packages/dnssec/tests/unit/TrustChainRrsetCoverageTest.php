<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\BrokenTrustChainException;
use Psl\DNSSEC\Internal\KeyTag;
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
use function str_repeat;
use function substr_count;
use function time;

final class TrustChainRrsetCoverageTest extends TestCase
{
    public function testRejectsUnsignedDsBesideIrrelevantRrsig(): void
    {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $attackerPair = sodium_crypto_sign_keypair();
        $attacker = self::key('example', $attackerPair);
        $forgedDs = self::ds($attacker);
        // Garbage RRSIG: covers type A at a name with no A records, so verifyRrsigs() skips it.
        $junkSig = new RRSIGRecord(
            'example',
            Duration::seconds(300),
            RecordType::A,
            Algorithm::ED25519,
            1,
            300,
            time() + 3600,
            time() - 3600,
            KeyTag::compute($root),
            '.',
            str_repeat("\0", 64),
        );

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
                ['example.', RecordType::DS] => new Response(1, ResponseCode::NoError, [$forgedDs, $junkSig], [], []),
                ['example.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$attacker, self::sign([$attacker], $attacker, $attackerPair)],
                    [],
                    [],
                ),
                default => throw new LogicException($name . ' ' . $type->name),
            });

        $result = new TrustChainResolver($inner, new TrustAnchor([self::ds($root)]))->resolve('example');
        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame([], $result->keys);
    }

    public function testRejectsUnsignedNsecBesideIrrelevantRrsig(): void
    {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $forgedNsec = new NSECRecord('example', Duration::seconds(300), 'f.example', [RecordType::NS]);
        $junkSig = new RRSIGRecord(
            '.',
            Duration::seconds(300),
            RecordType::SOA,
            Algorithm::ED25519,
            0,
            300,
            time() + 3600,
            time() - 3600,
            KeyTag::compute($root),
            '.',
            str_repeat("\0", 64),
        );
        $forgedA = new ARecord('www.example', Duration::seconds(300), Address::v4('203.0.113.66'));

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
                ['example.', RecordType::DS], ['www.example.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [],
                    [$forgedNsec, $junkSig],
                    [],
                ),
                ['www.example', RecordType::A] => new Response(1, ResponseCode::NoError, [$forgedA], [], []),
                default => throw new LogicException($name . ' ' . $type->name),
            });

        $chain = new TrustChainResolver($inner, new TrustAnchor([self::ds($root)]));
        static::assertSame(TrustChainStatus::Bogus, $chain->resolve('example')->status);
        $this->expectException(BrokenTrustChainException::class);
        new SecureResolver($inner, $chain)->query('www.example', RecordType::A);
    }

    public function testRejectsDsAtAnotherOwner(): void
    {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $attackerPair = sodium_crypto_sign_keypair();
        $attacker = self::key('example', $attackerPair);
        $ds = self::ds($attacker);
        $foreignDs = new DSRecord('other', $ds->duration, $ds->keyTag, $ds->algorithm, $ds->digestType, $ds->digest);
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
                ['example.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$foreignDs, self::sign([$foreignDs], $root, $rootPair)],
                    [],
                    [],
                ),
                ['example.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$attacker, self::sign([$attacker], $attacker, $attackerPair)],
                    [],
                    [],
                ),
                default => throw new LogicException($name . ' ' . $type->name),
            });

        static::assertSame(
            TrustChainStatus::Bogus,
            new TrustChainResolver($inner, new TrustAnchor([self::ds($root)]))->resolve('example')->status,
        );
    }

    public function testAcceptsValidDsSignatureAfterInvalidSignature(): void
    {
        $rootPair = sodium_crypto_sign_keypair();
        $root = self::key('.', $rootPair);
        $attackerPair = sodium_crypto_sign_keypair();
        $attacker = self::key('example', $attackerPair);
        $ds = self::ds($attacker);
        $invalid = new RRSIGRecord(
            'example',
            Duration::seconds(300),
            RecordType::DS,
            Algorithm::ED25519,
            1,
            300,
            time() + 3600,
            time() - 3600,
            KeyTag::compute($root),
            '.',
            str_repeat("\0", 64),
        );
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
                ['example.', RecordType::DS] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$ds, $invalid, self::sign([$ds], $root, $rootPair)],
                    [],
                    [],
                ),
                ['example.', RecordType::DNSKEY] => new Response(
                    1,
                    ResponseCode::NoError,
                    [$attacker, self::sign([$attacker], $attacker, $attackerPair)],
                    [],
                    [],
                ),
                default => throw new LogicException($name . ' ' . $type->name),
            });

        static::assertSame(
            TrustChainStatus::Secure,
            new TrustChainResolver($inner, new TrustAnchor([self::ds($root)]))->resolve('example')->status,
        );
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
