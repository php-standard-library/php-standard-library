<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use ArrayObject;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverConvenienceMethodsTrait;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\Exception\BrokenTrustChainException;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Exception\SignatureFailedException;
use Psl\DNSSEC\Exception\UnsignedResponseException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\TrustAnchor;
use Psl\DNSSEC\TrustChainResolver;
use Psl\DNSSEC\TrustChainResolverInterface;
use Psl\DNSSEC\TrustChainResult;
use Psl\DNSSEC\TrustChainStatus;
use Psl\IP\Address;
use Psl\Str\Byte;
use RuntimeException;
use Throwable;

use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

final class SecureResolverTest extends TestCase
{
    public function testQueryReturnsEmptyResponseWithoutValidation(): void
    {
        $inner = self::createMockResolver([]);
        $trustChain = self::createMockTrustChainResolver([]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);

        $resolver->query('nonexistent.example.com', RecordType::A);
    }

    public function testQueryThrowsWhenNoRrsigInResponse(): void
    {
        $answers = [
            new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4')),
        ];

        $inner = self::createMockResolver($answers);
        $trustChain = self::createMockTrustChainResolver([]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('No RRSIG records found for \'example.com\'.');

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryThrowsWhenNoDnskeyForZone(): void
    {
        $rawKey = self::generateRsaRfc3110Key();

        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag = KeyTag::compute($dnskey);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            1_700_000_000,
            1_699_900_000,
            $keyTag,
            'example.com',
            'dummy-signature',
        );

        $answers = [
            new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4')),
            $rrsig,
        ];

        $inner = new class($answers) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            /** @param list<RecordInterface> $answers */
            public function __construct(
                private readonly array $answers,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                if ($type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [], [], []);
                }

                return new Response(1, ResponseCode::NoError, $this->answers, [], []);
            }
        };

        $trustChain = new TrustChainResolver($inner);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(BrokenTrustChainException::class);
        $this->expectExceptionMessageMatches('/MissingDnskey/');

        $resolver->query('example.com', RecordType::A);
    }

    public function testTrustAnchorDefaultsToRoot(): void
    {
        $anchor = TrustAnchor::root();

        static::assertCount(2, $anchor->anchors);
        static::assertSame(20_326, $anchor->anchors[0]->keyTag);
        static::assertSame(Algorithm::RSASHA256, $anchor->anchors[0]->algorithm);
        static::assertSame(DigestAlgorithm::SHA256, $anchor->anchors[0]->digestType);
    }

    public function testQueryPassesThroughUnsignedNegativeResponse(): void
    {
        $inner = new class() implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NonExistentDomain, [], [], []);
            }
        };

        $trustChain = self::createMockTrustChainResolver([]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);

        $resolver->query('nonexistent.example.com', RecordType::A);
    }

    public function testQueryValidatesNodataWithNsecProof(): void
    {
        $nsec = new NSECRecord('example.com', Duration::seconds(3600), 'mail.example.com', [
            RecordType::A,
            RecordType::SOA,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        $inner = new class($nsec) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly NSECRecord $nsec,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NoError, [], [$this->nsec], []);
            }
        };

        $trustChain = new TrustChainResolver($inner);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);

        $resolver->query('example.com', RecordType::AAAA);
    }

    public function testQueryThrowsOnInvalidNxdomainProof(): void
    {
        $nsec = new NSECRecord(
            'zzz.example.com',
            Duration::seconds(3600),
            'zzzz.example.com',
            [
                RecordType::A,
                RecordType::RRSIG,
                RecordType::NSEC,
            ],
        );

        $inner = new class($nsec) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly NSECRecord $nsec,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NonExistentDomain, [], [$this->nsec], []);
            }
        };

        $trustChain = new TrustChainResolver($inner);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);

        $resolver->query('beta.example.com', RecordType::A);
    }

    public function testQueryThrowsOnInvalidNodataProof(): void
    {
        $nsec = new NSECRecord('example.com', Duration::seconds(3600), 'mail.example.com', [
            RecordType::A,
            RecordType::AAAA,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        $inner = new class($nsec) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly NSECRecord $nsec,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NoError, [], [$this->nsec], []);
            }
        };

        $trustChain = new TrustChainResolver($inner);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);

        $resolver->query('example.com', RecordType::AAAA);
    }

    public function testValidateResponseCollectsMultipleSigners(): void
    {
        $rawKey = self::generateRsaRfc3110Key();

        $dnskey1 = new DNSKEYRecord('zone1.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag1 = KeyTag::compute($dnskey1);

        $dnskey2 = new DNSKEYRecord('zone2.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag2 = KeyTag::compute($dnskey2);

        $rrsig1 = new RRSIGRecord(
            'zone1.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag1,
            'zone1.com',
            'dummy-sig-1',
        );

        $rrsig2 = new RRSIGRecord(
            'zone2.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag2,
            'zone2.com',
            'dummy-sig-2',
        );

        $answers = [
            new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4')),
            $rrsig1,
            $rrsig2,
        ];

        $resolvedZones = new ArrayObject();
        $trustChain = new class($resolvedZones, $dnskey1, $dnskey2) implements TrustChainResolverInterface {
            public function __construct(
                private ArrayObject $zones,
                private readonly DNSKEYRecord $key1,
                private readonly DNSKEYRecord $key2,
            ) {}

            public function resolve(
                string $zone,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TrustChainResult {
                $this->zones->append($zone);

                if ($zone === 'zone1.com') {
                    return new TrustChainResult(TrustChainStatus::Secure, [$this->key1]);
                }

                if ($zone === 'zone2.com') {
                    return new TrustChainResult(TrustChainStatus::Secure, [$this->key2]);
                }

                return new TrustChainResult(TrustChainStatus::Secure, []);
            }
        };

        $inner = self::createMockResolver($answers);
        $resolver = new SecureResolver($inner, $trustChain);

        /** @mago-expect lint:no-empty-catch-clause */
        try {
            $resolver->query('example.com', RecordType::A);
        } catch (Throwable) {
        }

        $zones = $resolvedZones->getArrayCopy();
        static::assertContains('zone1.com', $zones);
        static::assertContains('zone2.com', $zones);
    }

    public function testQueryThrowsWhenRrsigsButNoNsecInNegativeResponse(): void
    {
        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::SOA,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            12_345,
            'example.com',
            'dummy-signature',
        );

        $inner = new class($rrsig) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly RRSIGRecord $rrsig,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NoError, [], [$this->rrsig], []);
            }
        };

        $trustChain = new TrustChainResolver($inner);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('Signed negative response missing NSEC/NSEC3 proof for \'example.com\'.');

        $resolver->query('example.com', RecordType::AAAA);
    }

    public function testQuerySucceedsWithValidRsaSignature(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        [, $otherKey] = self::generateRsaKeyPair();
        $nonMatchingDnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(3600),
            257,
            3,
            Algorithm::RSASHA256,
            $otherKey,
        );

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$nonMatchingDnskey, $dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);

        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertSame([$aRecord, $rrsig], $response->answers);
    }

    public function testQueryThrowsSignatureVerificationExceptionWithInvalidSignature(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);
        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            $keyTag,
            'example.com',
            'invalid-signature',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);
        $this->expectExceptionMessageMatches('/for A records/');

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryThrowsWhenNoMatchingKeyTagForRrsig(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            65_535,
            'example.com',
            'dummy',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryThrowsWhenAlgorithmMismatchForRrsig(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);
        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA512,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            $keyTag,
            'example.com',
            'dummy',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryDoesNotThrowResourceExhaustionForExactlyEightDnskeys(): void
    {
        $dnskeys = [];
        for ($i = 0; $i < 8; $i++) {
            $dnskeys[] = new DNSKEYRecord(
                'example.com',
                Duration::seconds(3600),
                257,
                3,
                Algorithm::RSASHA256,
                self::generateRsaRfc3110Key(),
            );
        }

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            12_345,
            'example.com',
            'dummy-signature',
        );

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));
        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => $dnskeys]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryDoesNotThrowResourceExhaustionForExactlyEightRrsigs(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $now = Timestamp::now()->getSeconds();
        $rrsigs = [];
        for ($i = 0; $i < 8; $i++) {
            $rrsigs[] = new RRSIGRecord(
                'example.com',
                Duration::seconds(3600),
                RecordType::A,
                Algorithm::RSASHA256,
                2,
                300,
                $now + 86_400,
                $now - 86_400,
                $keyTag,
                'example.com',
                'invalid-signature-' . $i,
            );
        }

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));
        $inner = self::createMockResolver([$aRecord, ...$rrsigs]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryIncludesDnskeyCountInExceptionMessage(): void
    {
        $dnskeys = [];
        for ($i = 0; $i < 10; $i++) {
            $dnskeys[] = new DNSKEYRecord(
                'example.com',
                Duration::seconds(3600),
                257,
                3,
                Algorithm::RSASHA256,
                self::generateRsaRfc3110Key(),
            );
        }

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            12_345,
            'example.com',
            'dummy-signature',
        );

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));
        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => $dnskeys]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('DNS response contains 10 DNSKEY records, exceeding safety limit.');

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryIncludesRrsigCountInExceptionMessage(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $now = Timestamp::now()->getSeconds();
        $rrsigs = [];
        for ($i = 0; $i < 10; $i++) {
            $rrsigs[] = new RRSIGRecord(
                'example.com',
                Duration::seconds(3600),
                RecordType::A,
                Algorithm::RSASHA256,
                2,
                300,
                $now + 86_400,
                $now - 86_400,
                $keyTag,
                'example.com',
                'invalid-signature-' . $i,
            );
        }

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));
        $inner = self::createMockResolver([$aRecord, ...$rrsigs]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(InvalidProofException::class);
        $this->expectExceptionMessage('DNS response contains 10 RRSIG records, exceeding safety limit.');

        $resolver->query('example.com', RecordType::A);
    }

    public function testQueryIncludesNameInUnsignedNegativeResponseMessage(): void
    {
        $inner = new class() implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $trustChain = self::createMockTrustChainResolver([]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);
        $this->expectExceptionMessageMatches('/^Unsigned negative response for .*unsigned\.example\.com/');

        $resolver->query('unsigned.example.com', RecordType::A);
    }

    public function testQueryIncludesNameInUnsignedNsecProofMessage(): void
    {
        $nsec = new NSECRecord('example.com', Duration::seconds(3600), 'mail.example.com', [
            RecordType::A,
            RecordType::SOA,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        $inner = new class($nsec) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly NSECRecord $nsec,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NoError, [], [$this->nsec], []);
            }
        };

        $trustChain = self::createMockTrustChainResolver([]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(UnsignedResponseException::class);
        $this->expectExceptionMessageMatches('/^NSEC\/NSEC3 proof is not signed for .*unsigned\.example\.com/');

        $resolver->query('unsigned.example.com', RecordType::AAAA);
    }

    public function testQueryCollectsSignersPastDuplicateEntries(): void
    {
        $rawKey = self::generateRsaRfc3110Key();

        $dnskey1 = new DNSKEYRecord('zone1.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag1 = KeyTag::compute($dnskey1);

        $dnskey2 = new DNSKEYRecord('zone2.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rawKey);
        $keyTag2 = KeyTag::compute($dnskey2);

        $rrsig1 = new RRSIGRecord(
            'zone1.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag1,
            'zone1.com',
            'dummy-sig-1',
        );

        $rrsig1dup = new RRSIGRecord(
            'zone1.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag1,
            'zone1.com',
            'dummy-sig-1-dup',
        );

        $rrsig2 = new RRSIGRecord(
            'zone2.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            2_000_000_000,
            1_000_000_000,
            $keyTag2,
            'zone2.com',
            'dummy-sig-2',
        );

        $answers = [
            new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4')),
            $rrsig1,
            $rrsig1dup,
            $rrsig2,
        ];

        $resolvedZones = new ArrayObject();
        $trustChain = new class($resolvedZones, $dnskey1, $dnskey2) implements TrustChainResolverInterface {
            public function __construct(
                private ArrayObject $zones,
                private readonly DNSKEYRecord $key1,
                private readonly DNSKEYRecord $key2,
            ) {}

            public function resolve(
                string $zone,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TrustChainResult {
                $this->zones->append($zone);

                if ($zone === 'zone1.com') {
                    return new TrustChainResult(TrustChainStatus::Secure, [$this->key1]);
                }

                if ($zone === 'zone2.com') {
                    return new TrustChainResult(TrustChainStatus::Secure, [$this->key2]);
                }

                return new TrustChainResult(TrustChainStatus::Secure, []);
            }
        };

        $inner = self::createMockResolver($answers);
        $resolver = new SecureResolver($inner, $trustChain);

        /** @mago-expect lint:no-empty-catch-clause */
        try {
            $resolver->query('example.com', RecordType::A);
        } catch (Throwable) {
        }

        $zones = $resolvedZones->getArrayCopy();
        static::assertContains('zone1.com', $zones);
        static::assertContains('zone2.com', $zones);
    }

    public function testInsecureZonePositiveResponseReturnsAdFalse(): void
    {
        $answers = [
            new ARecord('insecure.example.com', Duration::seconds(300), Address::v4('1.2.3.4')),
        ];

        $inner = self::createMockResolver($answers);
        $trustChain = new class() implements TrustChainResolverInterface {
            public function resolve(
                string $zone,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TrustChainResult {
                return new TrustChainResult(TrustChainStatus::Insecure, []);
            }
        };

        $resolver = new SecureResolver($inner, $trustChain);
        $response = $resolver->query('insecure.example.com', RecordType::A);

        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertFalse($response->authenticatedData);
        static::assertCount(1, $response->answers);
    }

    public function testInsecureZoneNegativeResponseReturnsAdFalse(): void
    {
        $inner = new class() implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NonExistentDomain, [], [], []);
            }
        };

        $trustChain = new class() implements TrustChainResolverInterface {
            public function resolve(
                string $zone,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TrustChainResult {
                return new TrustChainResult(TrustChainStatus::Insecure, []);
            }
        };

        $resolver = new SecureResolver($inner, $trustChain);
        $response = $resolver->query('nonexistent.insecure.example.com', RecordType::A);

        static::assertSame(ResponseCode::NonExistentDomain, $response->code);
        static::assertFalse($response->authenticatedData);
    }

    public function testQuerySucceedsWithMixedCaseRecordNames(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('Example.COM', Duration::seconds(300), Address::v4('1.2.3.4'));

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.COM',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);

        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertCount(2, $response->answers);
    }

    public function testSecureZoneStillValidatesNormally(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4'));

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);

        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertCount(2, $response->answers);
    }

    public function testVerifyRrsigsCatchesSignatureFailedFromShortKey(): void
    {
        $badDnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, "\x01\x02");
        $badKeyTag = KeyTag::compute($badDnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'));
        $now = Timestamp::now()->getSeconds();

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            $badKeyTag,
            'example.com',
            'dummy-sig',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$badDnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);
        $this->expectExceptionMessageMatches('/for A records/');

        $resolver->query('example.com', RecordType::A);
    }

    public function testVerifyRrsigsCatchesExceptionAndContinuesLoop(): void
    {
        $badDnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, "\x01\x02");
        $badKeyTag = KeyTag::compute($badDnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.2'));
        $now = Timestamp::now()->getSeconds();

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            $badKeyTag,
            'example.com',
            'invalid',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$badDnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        try {
            $resolver->query('example.com', RecordType::A);
            static::fail('Expected SignatureFailedException');
        } catch (SignatureFailedException $e) {
            static::assertSame('A', $e->typeCovered);
        }
    }

    public function testVerifyRrsigsCatchesEmptyKeyException(): void
    {
        $badDnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, '');
        $badKeyTag = KeyTag::compute($badDnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.3'));
        $now = Timestamp::now()->getSeconds();

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            $badKeyTag,
            'example.com',
            'sig-data',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$badDnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);
        $this->expectExceptionMessageMatches('/for A records/');

        $resolver->query('example.com', RecordType::A);
    }

    public function testVerifyRrsigsMatchesCaseInsensitiveRecordNames(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('Example.Com', Duration::seconds(300), Address::v4('10.0.0.4'));
        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'Example.Com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response->code);
    }

    public function testVerifyRrsigsSkipsKeyWithWrongKeyTag(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.5'));
        $now = Timestamp::now()->getSeconds();

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            65_534,
            'example.com',
            'dummy',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('example.com', RecordType::A);
    }

    public function testVerifyRrsigsSkipsKeyWithWrongAlgorithm(): void
    {
        $rfc3110Key = self::generateRsaRfc3110Key();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.6'));
        $now = Timestamp::now()->getSeconds();

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA512,
            2,
            300,
            $now + 86_400,
            $now - 86_400,
            $keyTag,
            'example.com',
            'dummy',
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $this->expectException(SignatureFailedException::class);
        $resolver->query('example.com', RecordType::A);
    }

    public function testVerifyRrsigsOnlyMatchesRecordsByTypeAndName(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('example.com', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.7'));
        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$dnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertCount(2, $response->answers);
    }

    public function testVerifyRrsigsBreaksOnFirstSuccessfulKeyVerification(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $goodDnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(3600),
            257,
            3,
            Algorithm::RSASHA256,
            $rfc3110Key,
        );
        $keyTag = KeyTag::compute($goodDnskey);

        [, $otherRfc3110Key] = self::generateRsaKeyPair();
        $otherDnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(3600),
            257,
            3,
            Algorithm::RSASHA256,
            $otherRfc3110Key,
        );

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.8'));
        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$goodDnskey, $otherDnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response->code);
    }

    public function testVerifyRrsigsKeyTagOrAlgorithmMismatchSkips(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $goodDnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(3600),
            257,
            3,
            Algorithm::RSASHA256,
            $rfc3110Key,
        );
        $keyTag = KeyTag::compute($goodDnskey);

        $wrongAlgoDnskey = new DNSKEYRecord(
            'example.com',
            Duration::seconds(3600),
            257,
            3,
            Algorithm::RSASHA512,
            $rfc3110Key,
        );

        $aRecord = new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.9'));
        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildARecordSignedData($aRecord, $keyTag, 'example.com', $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            'example.com',
            Duration::seconds(3600),
            RecordType::A,
            Algorithm::RSASHA256,
            2,
            300,
            $expiration,
            $inception,
            $keyTag,
            'example.com',
            $signature,
        );

        $inner = self::createMockResolver([$aRecord, $rrsig]);
        $trustChain = self::createMockTrustChainResolver(['example.com' => [$wrongAlgoDnskey, $goodDnskey]]);
        $resolver = new SecureResolver($inner, $trustChain);

        $response = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response->code);
    }

    /**
     * @param list<RecordInterface> $answers
     */
    private static function createMockResolver(array $answers): ResolverInterface
    {
        return new class($answers) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            /** @param list<RecordInterface> $answers */
            public function __construct(
                private readonly array $answers,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return new Response(1, ResponseCode::NoError, $this->answers, [], []);
            }
        };
    }

    /**
     * @param array<string, list<DNSKEYRecord>> $zoneKeys
     */
    private static function createMockTrustChainResolver(array $zoneKeys): TrustChainResolverInterface
    {
        return new class($zoneKeys) implements TrustChainResolverInterface {
            /** @param array<string, list<DNSKEYRecord>> $zoneKeys */
            public function __construct(
                private readonly array $zoneKeys,
            ) {}

            public function resolve(
                string $zone,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TrustChainResult {
                return new TrustChainResult(TrustChainStatus::Secure, $this->zoneKeys[$zone] ?? []);
            }
        };
    }

    private static function buildARecordSignedData(
        ARecord $record,
        int $keyTag,
        string $signer,
        int $expiration,
        int $inception,
    ): string {
        $signerWire = Encoder::encodeName($signer);
        $rrsigPrefix = new Writer()
            ->u16(RecordType::A->value)
            ->u8(8)
            ->u8(2)
            ->u32(300)
            ->u32($expiration)
            ->u32($inception)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName(Byte\lowercase($record->name));
        $rdataWriter = new Writer();
        foreach (Byte\split($record->address->toString(), '.') as $part) {
            $rdataWriter = $rdataWriter->u8((int) $part);
        }

        $rdata = $rdataWriter->toString();
        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::A->value)
            ->u16(1)
            ->u32(300)
            ->u16(Byte\length($rdata))
            ->bytes($rdata)
            ->toString();

        return $rrsigPrefix . $canonicalRr;
    }

    /**
     * @return array{OpenSSLAsymmetricKey, string}
     */
    private static function generateRsaKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Failed to generate RSA key pair');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Failed to get RSA key details');
        }

        if (!isset($details['rsa']['e']) || !is_string($details['rsa']['e'])) {
            throw new RuntimeException('Missing RSA exponent in key details');
        }

        if (!isset($details['rsa']['n']) || !is_string($details['rsa']['n'])) {
            throw new RuntimeException('Missing RSA modulus in key details');
        }

        $exponent = $details['rsa']['e'];
        $modulus = $details['rsa']['n'];
        $expLen = Byte\length($exponent);

        if ($expLen < 256) {
            return [$key, Byte\chr($expLen) . $exponent . $modulus];
        }

        return [$key, "\x00" . Byte\chr(($expLen >> 8) & 0xFF) . Byte\chr($expLen & 0xFF) . $exponent . $modulus];
    }

    private static function generateRsaRfc3110Key(): string
    {
        [, $key] = self::generateRsaKeyPair();

        return $key;
    }
}
