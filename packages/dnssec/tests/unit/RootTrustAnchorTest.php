<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\ChainFailure;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\TrustAnchor;
use Psl\DNSSEC\TrustChainResolver;
use Psl\DNSSEC\TrustChainStatus;
use ReflectionMethod;

use function base64_decode;
use function strtolower;
use function time;

final class RootTrustAnchorTest extends TestCase
{
    /**
     * The DS values are published in RFC 9718, section 2.3.
     * The public keys must reproduce those digests and key tags.
     */
    public static function keys(): iterable
    {
        yield 'KSK-2017' => [
            20_326,
            'E06D44B80B8F1D39A95C0B0D7C65D08458E880409BBC683457104237C7F8EC8D',
            'AwEAAaz/tAm8yTn4Mfeh5eyI96WSVexTBAvkMgJzkKTOiW1vkIbzxeF3+/4Rg'
                . 'WOq7HrxRixHlFlExOLAJr5emLvN7SWXgnLh4+B5xQlNVz8Og8kvArMtNROxVQ'
                . 'uCaSnIDdD5LKyWbRd2n9WGe2R8PzgCmr3EgVLrjyBxWezF0jLHwVN8efS3rCj'
                . '/EWgvIWgb9tarpVUDK/b58Da+sqqls3eNbuv7pr+eoZG+SrDK6nWeL3c6H5Ap'
                . 'xz7LjVc1uTIdsIXxuOLYA4/ilBmSVIzuDWfdRUfhHdY6+cn8HFRm+2hM8AnXG'
                . 'Xws9555KrUB5qihylGa8subX2Nn6UwNR1AkUTV74bU=',
        ];
        yield 'KSK-2024' => [
            38_696,
            '683D2D0ACB8C9B712A1948B27F741219298D0A450D612C483AF444A4C0FB2B16',
            'AwEAAa96jeuknZlaeSrvyAJj6ZHv28hhOKkx3rLGXVaC6rXTsDc449/cidltpkyGw'
                . 'CJNnOAlFNKF2jBosZBU5eeHspaQWOmOElZsjICMQMC3aeHbGiShvZsx4wMYSjH8'
                . 'e7Vrhbu6irwCzVBApESjbUdpWWmEnhathWu1jo+siFUiRAAxm9qyJNg/wOZqqzL/'
                . 'dL/q8PkcRU5oUKEpUge71M3ej2/7CPqpdVwuMoTvoB+ZOT4YeGyxMvHmbrxlFzG'
                . 'OHOijtzN+u1TQNatX2XBuzZNQ1K+s2CXkPIZo7s6JgZyvaBevYtxPvYLw4z9mR7'
                . 'K2vaF18UYH9Z9GNUUeayffKC73PYc=',
        ];
    }

    #[DataProvider('keys')]
    public function testRootAnchorMatchesPublishedDnskey(int $tag, string $digest, string $publicKey): void
    {
        $key = self::key($publicKey);
        static::assertSame($tag, KeyTag::compute($key));
        $anchors = TrustAnchor::root()->anchors;
        static::assertCount(2, $anchors);
        static::assertTrue(self::matchesKey($anchors, $key));
        foreach ($anchors as $anchor) {
            if ($anchor->keyTag !== $tag) {
                continue;
            }

            static::assertSame(Algorithm::RSASHA256, $anchor->algorithm);
            static::assertSame(DigestAlgorithm::SHA256, $anchor->digestType);
            static::assertSame($digest, $anchor->digest);
            static::assertTrue(self::matchesKey([$anchor], $key));
        }
    }

    #[DataProvider('keys')]
    public function testChainAcceptsAnchorBeforeCheckingSignature(int $tag, string $_digest, string $publicKey): void
    {
        $key = self::key($publicKey);
        $now = time();
        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(300),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            300,
            $now + 3600,
            $now - 3600,
            $tag,
            '.',
            'invalid signature',
        );
        $inner = $this->createStub(ResolverInterface::class);
        $inner->method('query')->willReturn(new Response(1, ResponseCode::NoError, [$key, $rrsig], [], []));
        $result = new TrustChainResolver($inner)->resolve('.');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::SignatureVerificationFailed, $result->failure);
    }

    #[DataProvider('keys')]
    public function testDigestCaseDoesNotChangeMatching(int $tag, string $digest, string $publicKey): void
    {
        $key = self::key($publicKey);
        foreach ([$digest, strtolower($digest)] as $case) {
            $anchor = new DSRecord('.', Duration::zero(), $tag, Algorithm::RSASHA256, DigestAlgorithm::SHA256, $case);
            static::assertTrue(self::matchesKey([$anchor], $key));
        }
    }

    #[DataProvider('keys')]
    public function testMatchingDigestCannotOverrideWrongTag(int $tag, string $digest, string $publicKey): void
    {
        $anchor = new DSRecord('.', Duration::zero(), $tag + 1, Algorithm::RSASHA256, DigestAlgorithm::SHA256, $digest);
        static::assertFalse(self::matchesKey([$anchor], self::key($publicKey)));
    }

    private static function key(string $publicKey): DNSKEYRecord
    {
        $decoded = base64_decode($publicKey, true);
        static::assertNotFalse($decoded);
        return new DNSKEYRecord('.', Duration::seconds(300), 257, 3, Algorithm::RSASHA256, $decoded);
    }

    /** @param list<DSRecord> $anchors */
    private static function matchesKey(array $anchors, DNSKEYRecord $key): bool
    {
        return new ReflectionMethod(TrustChainResolver::class, 'matchDsToKey')->invoke(null, $anchors, [$key], '.');
    }
}
