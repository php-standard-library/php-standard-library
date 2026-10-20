<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\InvalidProofException;
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
use function str_repeat;
use function strlen;
use function time;

final class ReviewAnswerPocTest extends TestCase
{
    public function testP4RejectsForeignInsecureSignerWithAnotherOwner(): void
    {
        $forgedA = new ARecord('www.victim.example', Duration::seconds(300), Address::v4('203.0.113.66'));
        $foreignSig = new RRSIGRecord(
            'attacker.example',
            Duration::seconds(300),
            RecordType::A,
            Algorithm::ED25519,
            2,
            300,
            time() + 3600,
            time() - 3600,
            1,
            'attacker.example',
            str_repeat("\0", 64),
        );
        $inner = $this->createStub(ResolverInterface::class);
        $inner->method('query')->willReturn(new Response(1, ResponseCode::NoError, [$forgedA, $foreignSig], [], []));
        $chain = $this->createStub(TrustChainResolverInterface::class);
        $chain
            ->method('resolve')
            ->willReturnCallback(static fn(string $zone): TrustChainResult => match ($zone) {
                'attacker.example' => new TrustChainResult(TrustChainStatus::Insecure, []),
                default => new TrustChainResult(TrustChainStatus::Secure, [self::key(
                    'victim.example',
                    sodium_crypto_sign_keypair(),
                )]),
            });

        $this->expectException(InvalidProofException::class);
        new SecureResolver($inner, $chain)->query('www.victim.example', RecordType::A);
    }

    public function testP5RejectsForgedAnswerWithUnrelatedRrsig(): void
    {
        $victimPair = sodium_crypto_sign_keypair();
        $victim = self::key('victim.example', $victimPair);
        $forgedA = new ARecord('www.victim.example', Duration::seconds(300), Address::v4('203.0.113.66'));
        $junkSig = new RRSIGRecord(
            'nothing.victim.example',
            Duration::seconds(300),
            RecordType::A,
            Algorithm::ED25519,
            3,
            300,
            time() + 3600,
            time() - 3600,
            KeyTag::compute($victim),
            'victim.example',
            str_repeat("\0", 64),
        );
        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturn(new Response(1, ResponseCode::NoError, [$forgedA, $junkSig], [], [], authenticatedData: true));
        $chain = $this->createStub(TrustChainResolverInterface::class);
        $chain->method('resolve')->willReturn(new TrustChainResult(TrustChainStatus::Secure, [$victim]));

        $this->expectException(InvalidProofException::class);
        new SecureResolver($inner, $chain)->query('www.victim.example', RecordType::A);
    }

    /** @return iterable<string, array{bool}> */
    public static function querySignatures(): iterable
    {
        yield 'valid query signature' => [true];
        yield 'invalid query signature' => [false];
    }

    #[DataProvider('querySignatures')]
    public function testInsecureAliasTargetDoesNotBypassQuerySignature(bool $valid): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = self::key('victim.example', $pair);
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'host.attacker.example');
        $target = new ARecord('host.attacker.example', Duration::seconds(300), Address::v4('203.0.113.66'));
        $prefix =
            pack(
                'nCCNNNn',
                RecordType::CNAME->value,
                Algorithm::ED25519->value,
                3,
                300,
                2_000_000_000,
                1_000_000_000,
                KeyTag::compute($key),
            ) . Encoder::encodeName('victim.example');
        $rdata = Encoder::encodeName('host.attacker.example');
        $data =
            $prefix
            . Encoder::encodeName('www.victim.example')
            . pack('nnNn', RecordType::CNAME->value, 1, 300, strlen($rdata))
            . $rdata;
        $signature = new RRSIGRecord(
            'www.victim.example',
            Duration::seconds(300),
            RecordType::CNAME,
            Algorithm::ED25519,
            3,
            300,
            2_000_000_000,
            1_000_000_000,
            KeyTag::compute($key),
            'victim.example',
            $valid ? sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($pair)) : str_repeat("\0", 64),
        );
        $targetSignature = new RRSIGRecord(
            'host.attacker.example',
            Duration::seconds(300),
            RecordType::A,
            Algorithm::ED25519,
            3,
            300,
            2_000_000_000,
            1_000_000_000,
            1,
            'attacker.example',
            str_repeat("\0", 64),
        );
        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturn(
                new Response(
                    1,
                    ResponseCode::NoError,
                    [$alias, $signature, $target, $targetSignature],
                    [],
                    [],
                    authenticatedData: true,
                ),
            );
        $chain = $this->createStub(TrustChainResolverInterface::class);
        $chain->method('resolve')->willReturnCallback(static fn(string $zone): TrustChainResult => (
            RRSIGVerifier::isSignerForName('attacker.example', $zone)
                ? new TrustChainResult(TrustChainStatus::Insecure, [])
                : new TrustChainResult(TrustChainStatus::Secure, [$key])
        ));

        if (!$valid) {
            $this->expectException(SignatureFailedException::class);
        }

        $response = new SecureResolver($inner, $chain)->query('www.victim.example', RecordType::A);
        static::assertFalse($response->authenticatedData);
        static::assertSame([$alias, $signature, $target, $targetSignature], $response->answers);
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
}
