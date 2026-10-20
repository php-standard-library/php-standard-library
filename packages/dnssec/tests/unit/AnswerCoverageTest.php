<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\DNAMERecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\StaticTrustChainResolver;
use Psl\IP\Address;

use function pack;
use function rtrim;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_keypair;
use function sodium_crypto_sign_publickey;
use function sodium_crypto_sign_secretkey;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr_count;

final class AnswerCoverageTest extends TestCase
{
    public function testUnsignedAnswerCannotBorrowAnotherRrsetsSignature(): void
    {
        $victim = self::address('www.victim.example');
        [$signature, $key] = self::sign(self::address('attacker.example'), 'attacker.example');
        [, $victimKey] = self::sign($victim, 'victim.example');
        $resolver = $this->resolver([$victim, self::address('attacker.example'), $signature], [$key, $victimKey]);

        $this->expectException(InvalidProofException::class);
        $resolver->query($victim->name, RecordType::A);
    }

    public function testUnsignedCnameCannotBorrowTargetsSignature(): void
    {
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'target.example');
        $target = self::address($alias->target);
        [$signature, $key] = self::sign($target, 'example');
        $resolver = $this->resolver([$alias, $target, $signature], [$key]);

        $this->expectException(InvalidProofException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testUnsignedTargetCannotBorrowCnamesSignature(): void
    {
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'target.example');
        [$signature, $key] = self::sign($alias, 'example');
        $resolver = $this->resolver([$alias, $signature, self::address($alias->target)], [$key]);

        $this->expectException(InvalidProofException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testSignedCnameChainUsesEachZonesKeys(): void
    {
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'target.other');
        $target = self::address($alias->target);
        [$aliasSignature, $aliasKey] = self::sign($alias, 'victim.example');
        [$targetSignature, $targetKey] = self::sign($target, 'other');
        $answers = [$alias, $aliasSignature, $target, $targetSignature];
        $resolver = $this->resolver($answers, [$aliasKey, $targetKey]);

        static::assertSame($answers, $resolver->query('WWW.VICTIM.EXAMPLE.', RecordType::A)->answers);
    }

    public function testStripsUnrelatedUnsignedAnswer(): void
    {
        $record = self::address('www.victim.example');
        [$signature, $key] = self::sign($record, 'victim.example');
        $resolver = $this->resolver([$record, $signature, self::address('attacker.example')], [$key]);

        static::assertSame([$record, $signature], $resolver->query($record->name, RecordType::A)->answers);
    }

    public function testUnrelatedInsecureSignerCannotDowngradeAnswer(): void
    {
        $record = self::address('www.victim.example');
        [$signature, $key] = self::sign($record, 'victim.example');
        $extra = self::address('attacker.example');
        [$extraSignature] = self::sign($extra, 'attacker.example');
        $resolver = $this->resolver([$record, $signature, $extra, $extraSignature], [$key], ['attacker.example']);

        $response = $resolver->query($record->name, RecordType::A);
        static::assertTrue($response->authenticatedData);
        static::assertSame([$record, $signature], $response->answers);
    }

    public function testParentKeyCannotSignBelowSecureDelegation(): void
    {
        $record = self::address('www.child.example');
        [$signature, $parentKey] = self::sign($record, 'example');
        [, $childKey] = self::sign($record, 'child.example');
        $resolver = $this->resolver([$record, $signature], [$parentKey, $childKey]);

        $this->expectException(SignatureFailedException::class);
        $resolver->query($record->name, RecordType::A);
    }

    public function testSignedDnameAuthenticatesSynthesizedCname(): void
    {
        $dname = new DNAMERecord('victim.example', Duration::seconds(300), 'other.example');
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'www.other.example');
        $target = self::address($alias->target);
        [$dnameSignature, $dnameKey] = self::sign($dname, 'victim.example');
        [$targetSignature, $targetKey] = self::sign($target, 'other.example');
        $answers = [$dname, $dnameSignature, $alias, $target, $targetSignature];
        $resolver = $this->resolver($answers, [$dnameKey, $targetKey]);

        static::assertSame($answers, $resolver->query($alias->name, RecordType::A)->answers);
        static::assertSame(
            [$dname, $dnameSignature, $alias],
            $resolver->query($alias->name, RecordType::CNAME)->answers,
        );
    }

    public function testUnsignedDnameCannotBorrowTargetsSignature(): void
    {
        $dname = new DNAMERecord('victim.example', Duration::seconds(300), 'other.example');
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'www.other.example');
        $target = self::address($alias->target);
        [$signature, $key] = self::sign($target, 'example');
        $resolver = $this->resolver([$dname, $alias, $target, $signature], [$key]);

        $this->expectException(InvalidProofException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testDnameRejectsTamperedSynthesizedCname(): void
    {
        $dname = new DNAMERecord('victim.example', Duration::seconds(300), 'other.example');
        $alias = new CNAMERecord('www.victim.example', Duration::seconds(300), 'attacker.example');
        $target = self::address($alias->target);
        [$dnameSignature, $dnameKey] = self::sign($dname, 'victim.example');
        [$targetSignature, $targetKey] = self::sign($target, 'example');
        $resolver = $this->resolver([$dname, $dnameSignature, $alias, $target, $targetSignature], [
            $dnameKey,
            $targetKey,
        ]);

        $this->expectException(InvalidProofException::class);
        $resolver->query($alias->name, RecordType::A);
    }

    public function testDnameChainDoesNotRequireSynthesizedCname(): void
    {
        $dname = new DNAMERecord('victim.example', Duration::seconds(300), 'other.example');
        $target = self::address('www.other.example');
        [$dnameSignature, $dnameKey] = self::sign($dname, 'victim.example');
        [$targetSignature, $targetKey] = self::sign($target, 'other.example');
        $answers = [$dname, $dnameSignature, $target, $targetSignature];
        $resolver = $this->resolver($answers, [$dnameKey, $targetKey]);

        static::assertSame($answers, $resolver->query('www.victim.example', RecordType::A)->answers);
    }

    public function testDnameCannotGrowTheAliasChainWithoutBound(): void
    {
        $dname = new DNAMERecord('example', Duration::seconds(300), 'sub.example');
        [$signature, $key] = self::sign($dname, 'example');
        $resolver = $this->resolver([$dname, $signature], [$key]);

        $this->expectException(InvalidProofException::class);
        $resolver->query('www.example', RecordType::A);
    }

    public function testDnameRejectsOverlongSubstitution(): void
    {
        $target = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.example';
        $dname = new DNAMERecord('example', Duration::seconds(300), $target);
        [$signature, $key] = self::sign($dname, 'example');
        $resolver = $this->resolver([$dname, $signature], [$key]);

        $this->expectException(InvalidArgumentException::class);
        $resolver->query(str_repeat('d', 63) . '.example', RecordType::A);
    }

    public function testRejectsWildcardExpandedDname(): void
    {
        $wildcard = new DNAMERecord('*.example', Duration::seconds(300), 'other.example');
        [$signature, $key] = self::sign($wildcard, 'example', 1);
        $dname = new DNAMERecord('victim.example', $wildcard->duration, $wildcard->target);
        $expanded = new RRSIGRecord(
            $dname->name,
            $signature->duration,
            $signature->typeCovered,
            $signature->algorithm,
            $signature->labels,
            $signature->originalTtl,
            $signature->expiration,
            $signature->inception,
            $signature->keyTag,
            $signature->signer,
            $signature->signature,
        );
        $resolver = $this->resolver([$dname, $expanded], [$key]);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('www.victim.example', RecordType::A);
    }

    /**
     * @param list<RecordInterface> $answers
     * @param list<DNSKEYRecord> $keys
     * @param list<string> $insecureZones
     */
    private function resolver(array $answers, array $keys, array $insecureZones = []): SecureResolver
    {
        $inner = $this->createStub(ResolverInterface::class);
        $inner
            ->method('query')
            ->willReturn(new Response(1, ResponseCode::NoError, $answers, [], [], authenticatedData: true));
        $zones = [];
        foreach ($keys as $key) {
            $zones[$key->name][] = $key;
        }

        return new SecureResolver($inner, new StaticTrustChainResolver($zones, $insecureZones));
    }

    private static function address(string $name): ARecord
    {
        return new ARecord($name, Duration::seconds(300), Address::v4('192.0.2.66'));
    }

    /** @return array{RRSIGRecord, DNSKEYRecord} */
    private static function sign(
        ARecord|CNAMERecord|DNAMERecord $record,
        string $signer,
        null|int $labels = null,
    ): array {
        $pair = sodium_crypto_sign_keypair();
        $key = new DNSKEYRecord(
            $signer,
            Duration::seconds(300),
            257,
            3,
            Algorithm::ED25519,
            sodium_crypto_sign_publickey($pair),
        );
        $owner = rtrim(strtolower($record->name), '.');
        $labels ??= substr_count($owner, '.') + 1;
        $prefix =
            pack(
                'nCCNNNn',
                $record->kind->value,
                Algorithm::ED25519->value,
                $labels,
                300,
                2_000_000_000,
                1_000_000_000,
                KeyTag::compute($key),
            ) . Encoder::encodeName($signer);
        $rdata = $record instanceof ARecord
            ? $record->address->toBytes()
            : Encoder::encodeName(strtolower($record->target));
        $data =
            $prefix . Encoder::encodeName($owner) . pack('nnNn', $record->kind->value, 1, 300, strlen($rdata)) . $rdata;
        $signature = new RRSIGRecord(
            $record->name,
            Duration::seconds(300),
            $record->kind,
            Algorithm::ED25519,
            $labels,
            300,
            2_000_000_000,
            1_000_000_000,
            KeyTag::compute($key),
            $signer,
            sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($pair)),
        );

        return [$signature, $key];
    }
}
