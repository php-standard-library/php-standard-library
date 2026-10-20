<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\Internal\RRSIG\RRSIGVerifier;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\TrustChainResolverInterface;
use Psl\DNSSEC\TrustChainResult;
use Psl\DNSSEC\TrustChainStatus;
use Psl\IP\Address;

use function pack;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function substr_count;

final class SignerScopeTest extends TestCase
{
    public function testVerifierRejectsAnotherZonesSignature(): void
    {
        [$record, $signature, $key] = self::signedAnswer('www.victim.example', 'attacker.example', 'attacker.example');

        static::assertFalse(RRSIGVerifier::verify($signature, $key, [$record]));
    }

    public function testVerifierRejectsKeyFromAnotherZone(): void
    {
        [$record, $signature, $key] = self::signedAnswer('www.victim.example', 'victim.example', 'attacker.example');

        static::assertFalse(RRSIGVerifier::verify($signature, $key, [$record]));
    }

    public function testVerifierRejectsNameWithSharedSuffix(): void
    {
        [$record, $signature, $key] = self::signedAnswer('notvictim.example', 'victim.example', 'victim.example');

        static::assertFalse(RRSIGVerifier::verify($signature, $key, [$record]));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validNames(): iterable
    {
        yield 'apex' => ['victim.example', 'victim.example', 'victim.example'];
        yield 'child' => ['www.victim.example', 'victim.example', 'victim.example'];
        yield 'case and final dot' => ['www.victim.example', 'victim.example', 'VICTIM.EXAMPLE.'];
        yield 'root' => ['example', '.', '.'];
    }

    #[DataProvider('validNames')]
    public function testVerifierAcceptsNamesWithinZone(string $owner, string $signer, string $keyOwner): void
    {
        [$record, $signature, $key] = self::signedAnswer($owner, $signer, $keyOwner);

        static::assertTrue(RRSIGVerifier::verify($signature, $key, [$record]));
    }

    /** @return iterable<string, array{TrustChainStatus}> */
    public static function chainStatuses(): iterable
    {
        yield 'secure attacker zone' => [TrustChainStatus::Secure];
        yield 'insecure attacker zone' => [TrustChainStatus::Insecure];
    }

    #[DataProvider('chainStatuses')]
    public function testResolverRejectsAnotherZonesSignature(TrustChainStatus $status): void
    {
        [$record, $signature, $key] = self::signedAnswer('www.victim.example', 'attacker.example', 'attacker.example');
        $inner = $this->createStub(ResolverInterface::class);
        $inner->method('query')->willReturn(new Response(1, ResponseCode::NoError, [$record, $signature], [], []));
        $chain = $this->createStub(TrustChainResolverInterface::class);
        $chain->method('resolve')->willReturn(new TrustChainResult($status, [$key]));
        $resolver = new SecureResolver($inner, $chain);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.victim.example', RecordType::A);
    }

    /** @return array{ARecord, RRSIGRecord, DNSKEYRecord} */
    private static function signedAnswer(string $owner, string $signer, string $keyOwner): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $key = new DNSKEYRecord(
            $keyOwner,
            Duration::seconds(300),
            257,
            3,
            Algorithm::ED25519,
            sodium_crypto_sign_publickey($keyPair),
        );
        $record = new ARecord($owner, Duration::seconds(300), Address::v4('192.0.2.1'));
        $labels = substr_count($owner, '.') + 1;
        $prefix =
            pack(
                'nCCNNNn',
                RecordType::A->value,
                Algorithm::ED25519->value,
                $labels,
                300,
                2_000_000_000,
                1_000_000_000,
                KeyTag::compute($key),
            ) . Encoder::encodeName($signer);
        $data =
            $prefix
            . Encoder::encodeName($owner)
            . pack('nnNn', RecordType::A->value, 1, 300, 4)
            . $record->address->toBytes();
        $signature = new RRSIGRecord(
            $owner,
            Duration::seconds(300),
            RecordType::A,
            Algorithm::ED25519,
            $labels,
            300,
            2_000_000_000,
            1_000_000_000,
            KeyTag::compute($key),
            $signer,
            sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($keyPair)),
        );

        return [$record, $signature, $key];
    }
}
