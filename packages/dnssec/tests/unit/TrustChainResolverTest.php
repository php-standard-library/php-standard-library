<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Binary\Writer;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Exception\ExceptionInterface;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Internal\TypeBitmap;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNS\Record\DSRecord;
use Psl\DNS\Record\NSECRecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNS\ResolverConvenienceMethodsTrait;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNSSEC\ChainFailure;
use Psl\DNSSEC\Exception\InvalidProofException;
use Psl\DNSSEC\Internal\KeyTag;
use Psl\DNSSEC\SecureResolver;
use Psl\DNSSEC\TrustAnchor;
use Psl\DNSSEC\TrustChainResolver;
use Psl\DNSSEC\TrustChainResolverInterface;
use Psl\DNSSEC\TrustChainResult;
use Psl\DNSSEC\TrustChainStatus;
use Psl\IP\Address;
use Psl\Str;
use Psl\Str\Byte;
use Psl\Vec;
use RuntimeException;

use function hash;
use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function rtrim;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

final class TrustChainResolverTest extends TestCase
{
    public function testResolveThrowsExactMessageWhenNoDnskeyForRootZone(): void
    {
        $inner = self::createEmptyResolver();
        $resolver = new TrustChainResolver($inner);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::MissingDnskey, $result->failure);
    }

    public function testResolveThrowsExactMessageWhenDnskeyNotSignedForRootZone(): void
    {
        $inner = self::createResolverWithDnskeyButNoRrsig();
        $resolver = new TrustChainResolver($inner);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::UnsignedDnskey, $result->failure);
    }

    public function testResolveThrowsWhenNoDnskeyForChildZone(): void
    {
        $inner = self::createEmptyResolver();
        $resolver = new TrustChainResolver($inner);

        $result = $resolver->resolve('example.com');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::MissingDnskey, $result->failure);
    }

    public function testResolveUsesProvidedTrustAnchor(): void
    {
        $customAnchor = new TrustAnchor([
            new DSRecord('.', Duration::seconds(0), 99_999, Algorithm::RSASHA256, DigestAlgorithm::SHA256, 'deadbeef'),
        ]);

        $inner = self::createEmptyResolver();
        $resolver = new TrustChainResolver($inner, $customAnchor);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::MissingDnskey, $result->failure);
    }

    /** @mago-expect lint:no-empty-catch-clause */
    public function testResolveQueriesRootWithDotForEmptyZone(): void
    {
        $queriedNames = [];

        $inner = new class($queriedNames) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            private array $names;

            public function __construct(array &$names)
            {
                $this->names = &$names;
            }

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                $this->names[] = $name;

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        try {
            $resolver = new TrustChainResolver($inner);
            $resolver->resolve('');
        } catch (ExceptionInterface) {
        }

        static::assertSame(['.'], $queriedNames);
    }

    /** @mago-expect lint:no-empty-catch-clause */
    public function testResolveQueriesRootFirstForChildZone(): void
    {
        $queriedNames = [];
        $queriedKinds = [];

        $inner = new class($queriedNames, $queriedKinds) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            private array $names;

            private array $kinds;

            public function __construct(array &$names, array &$kinds)
            {
                $this->names = &$names;
                $this->kinds = &$kinds;
            }

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                $this->names[] = $name;
                $this->kinds[] = $type;

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        try {
            $resolver = new TrustChainResolver($inner);
            $resolver->resolve('sub.example.com');
        } catch (ExceptionInterface) {
        }

        static::assertSame('.', $queriedNames[0]);
        static::assertSame(RecordType::DNSKEY, $queriedKinds[0]);
    }

    public function testTrustAnchorRootHasZeroDuration(): void
    {
        $anchor = TrustAnchor::root();

        static::assertSame(0, (int) $anchor->anchors[0]->duration->getTotalSeconds());
    }

    public function testExceptionMessageContainsQueryName(): void
    {
        $answers = [
            new ARecord('example.com', Duration::seconds(300), Address::v4('1.2.3.4')),
        ];

        $inner = self::createMockResolver($answers);
        $trustChain = new class() implements TrustChainResolverInterface {
            public function resolve(
                string $zone,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TrustChainResult {
                return new TrustChainResult(TrustChainStatus::Secure, []);
            }
        };
        $resolver = new SecureResolver($inner, $trustChain);

        try {
            $resolver->query('example.com', RecordType::A);
            static::fail('Expected MissingProofException');
        } catch (InvalidProofException $e) {
            static::assertStringContainsString('example.com', $e->getMessage());
        }
    }

    public function testExceptionMessageContainsZoneKeyword(): void
    {
        $inner = self::createEmptyResolver();
        $resolver = new TrustChainResolver($inner);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::MissingDnskey, $result->failure);
    }

    public function testResolveRejectsDnskeyWithZeroFlags(): void
    {
        $dnskey = new DNSKEYRecord(
            '.',
            Duration::seconds(3600),
            0,
            3,
            Algorithm::RSASHA256,
            "\x03\x01\x00\x01\xAB\xAB\xAB\xAB",
        );
        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                KeyTag::compute($dnskey),
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                'deadbeef',
            ),
        ]);

        $inner = new class($dnskey) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $dnskey,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                if ($type === RecordType::DNSKEY) {
                    $rrsig = new RRSIGRecord(
                        '.',
                        Duration::seconds(3600),
                        RecordType::DNSKEY,
                        Algorithm::RSASHA256,
                        0,
                        3600,
                        2_000_000_000,
                        1_000_000_000,
                        KeyTag::compute($this->dnskey),
                        '',
                        'dummy',
                    );

                    return new Response(1, ResponseCode::NoError, [$this->dnskey, $rrsig], [], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::TrustAnchorMismatch, $result->failure);
    }

    public function testResolveRejectsDnskeyWithInvalidProtocol(): void
    {
        $dnskey = new DNSKEYRecord(
            '.',
            Duration::seconds(3600),
            257,
            2,
            Algorithm::RSASHA256,
            "\x03\x01\x00\x01\xAB\xAB\xAB\xAB",
        );
        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                KeyTag::compute($dnskey),
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                'deadbeef',
            ),
        ]);

        $inner = new class($dnskey) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $dnskey,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                if ($type === RecordType::DNSKEY) {
                    $rrsig = new RRSIGRecord(
                        '.',
                        Duration::seconds(3600),
                        RecordType::DNSKEY,
                        Algorithm::RSASHA256,
                        0,
                        3600,
                        2_000_000_000,
                        1_000_000_000,
                        KeyTag::compute($this->dnskey),
                        '.',
                        'dummy',
                    );

                    return new Response(1, ResponseCode::NoError, [$this->dnskey, $rrsig], [], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('.');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::TrustAnchorMismatch, $result->failure);
    }

    public function testResolveRejectsDnskeyWithSepFlagOnly(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('.', Duration::seconds(3600), 1, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(1)
            ->u8(3)
            ->u8(8)
            ->bytes($rfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord('.', Duration::seconds(0), $keyTag, Algorithm::RSASHA256, DigestAlgorithm::SHA256, $digestHex),
        ]);

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $now + 86_400,
            $now - 86_400,
            $keyTag,
            '',
            'dummy',
        );

        $inner = self::createDnskeyResolver([$dnskey], $rrsig);
        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::TrustAnchorMismatch, $result->failure);
    }

    public function testResolveSucceedsForRootWithValidTrustChain(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord('.', Duration::seconds(0), $keyTag, Algorithm::RSASHA256, DigestAlgorithm::SHA256, $digestHex),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildDnskeySignedData([$dnskey], $keyTag, $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $keyTag,
            '',
            $signature,
        );

        $inner = self::createDnskeyResolver([$dnskey], $rrsig);
        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertCount(1, $result->keys);
        static::assertSame($dnskey, $result->keys[0]);
    }

    public function testResolveSucceedsWithMultipleDnskeysSkippingInvalidFlags(): void
    {
        [$privateKey, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskeyBad = new DNSKEYRecord('.', Duration::seconds(3600), 0, 3, Algorithm::RSASHA256, $rfc3110Key);
        $dnskeyGood = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTagGood = KeyTag::compute($dnskeyGood);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdataGood = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdataGood);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $keyTagGood,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildDnskeySignedData([$dnskeyBad, $dnskeyGood], $keyTagGood, $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $keyTagGood,
            '',
            $signature,
        );

        $inner = self::createDnskeyResolver([$dnskeyBad, $dnskeyGood], $rrsig);
        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertCount(2, $result->keys);
    }

    public function testResolveThrowsWhenDnskeyRrsigSignatureInvalid(): void
    {
        [, $rfc3110Key] = self::generateRsaKeyPair();
        $dnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rfc3110Key);
        $keyTag = KeyTag::compute($dnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord('.', Duration::seconds(0), $keyTag, Algorithm::RSASHA256, DigestAlgorithm::SHA256, $digestHex),
        ]);

        $now = Timestamp::now()->getSeconds();
        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $now + 86_400,
            $now - 86_400,
            $keyTag,
            '',
            'invalid-signature-data',
        );

        $inner = self::createDnskeyResolver([$dnskey], $rrsig);
        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::SignatureVerificationFailed, $result->failure);
    }

    public function testDsNonExistenceWithValidNsecProofReturnsInsecure(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $rootSignedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $rootSignature = null;
        openssl_sign($rootSignedData, $rootSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rootRrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $rootSignature,
        );

        $nsec = new NSECRecord(
            'com',
            Duration::seconds(3600),
            'com.au',
            [
                RecordType::NS,
                RecordType::RRSIG,
                RecordType::NSEC,
            ],
        );

        $nsecSignedData = self::buildNsecSignedData($nsec, $rootKeyTag, $expiration, $inception);
        $nsecSignature = null;
        openssl_sign($nsecSignedData, $nsecSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $nsecRrsig = new RRSIGRecord(
            'com',
            Duration::seconds(3600),
            RecordType::NSEC,
            Algorithm::RSASHA256,
            1,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $nsecSignature,
        );

        $inner = new class($rootDnskey, $rootRrsig, $nsec, $nsecRrsig) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $rootDnskey,
                private readonly RRSIGRecord $rootRrsig,
                private readonly NSECRecord $nsec,
                private readonly RRSIGRecord $nsecRrsig,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                if ($name === '.' && $type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [$this->rootDnskey, $this->rootRrsig], [], []);
                }

                if ($name === 'com' && $type === RecordType::DS) {
                    return new Response(1, ResponseCode::NoError, [], [$this->nsec, $this->nsecRrsig], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('com');

        static::assertSame(TrustChainStatus::Insecure, $result->status);
        static::assertSame([], $result->keys);
    }

    public function testDsNonExistenceWithoutNsecProofThrows(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $rootSignedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $rootSignature = null;
        openssl_sign($rootSignedData, $rootSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rootRrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $rootSignature,
        );

        $inner = new class($rootDnskey, $rootRrsig) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $rootDnskey,
                private readonly RRSIGRecord $rootRrsig,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                if ($name === '.' && $type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [$this->rootDnskey, $this->rootRrsig], [], []);
                }

                if ($name === 'com' && $type === RecordType::DS) {
                    return new Response(1, ResponseCode::NoError, [], [], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('com');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::MissingDs, $result->failure);
    }

    public function testDsNonExistenceWithUnsignedNsecThrows(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $rootSignedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $rootSignature = null;
        openssl_sign($rootSignedData, $rootSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rootRrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $rootSignature,
        );

        $nsec = new NSECRecord('com', Duration::seconds(3600), 'com.au', [
            RecordType::NS,
            RecordType::SOA,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        $inner = new class($rootDnskey, $rootRrsig, $nsec) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $rootDnskey,
                private readonly RRSIGRecord $rootRrsig,
                private readonly NSECRecord $nsec,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                if ($name === '.' && $type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [$this->rootDnskey, $this->rootRrsig], [], []);
                }

                if ($name === 'com' && $type === RecordType::DS) {
                    return new Response(1, ResponseCode::NoError, [], [$this->nsec], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('com');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::UnsignedDs, $result->failure);
    }

    public function testDsNonExistenceWithInvalidNsecRrsigReturnsBogus(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $rootSignedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $rootSignature = null;
        openssl_sign($rootSignedData, $rootSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rootRrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $rootSignature,
        );

        $nsec = new NSECRecord('com', Duration::seconds(3600), 'com.au', [
            RecordType::NS,
            RecordType::SOA,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        $nsecRrsig = new RRSIGRecord(
            'com',
            Duration::seconds(3600),
            RecordType::NSEC,
            Algorithm::RSASHA256,
            1,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            'invalid-signature-data',
        );

        $inner = new class($rootDnskey, $rootRrsig, $nsec, $nsecRrsig) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $rootDnskey,
                private readonly RRSIGRecord $rootRrsig,
                private readonly NSECRecord $nsec,
                private readonly RRSIGRecord $nsecRrsig,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                if ($name === '.' && $type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [$this->rootDnskey, $this->rootRrsig], [], []);
                }

                if ($name === 'com' && $type === RecordType::DS) {
                    return new Response(1, ResponseCode::NoError, [], [$this->nsec, $this->nsecRrsig], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('com');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::SignatureVerificationFailed, $result->failure);
    }

    public function testBuildZoneChainProducesCorrectIntermediateZones(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $rootSignedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $rootSignature = null;
        openssl_sign($rootSignedData, $rootSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rootRrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $rootSignature,
        );

        /** @var list<array{string, RecordType}> $queriedNames */
        $queriedNames = [];
        $inner = new class($rootDnskey, $rootRrsig, $queriedNames) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            /** @var list<array{string, RecordType}> */
            private array $names;

            /** @param list<array{string, RecordType}> $names */
            public function __construct(
                private readonly DNSKEYRecord $rootDnskey,
                private readonly RRSIGRecord $rootRrsig,
                array &$names,
            ) {
                $this->names = &$names;
            }

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                $this->names[] = [$name, $type];

                if ($name === '.' && $type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [$this->rootDnskey, $this->rootRrsig], [], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);
        $resolver->resolve('sub.example.com');

        $dsQueries = [];
        foreach ($queriedNames as [$name, $kind]) {
            if ($kind !== RecordType::DS) {
                continue;
            }

            $dsQueries[] = $name;
        }

        static::assertSame('com', $dsQueries[0]);
    }

    public function testEnforceRecordLimitsFiltersNonDnskeyRecords(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;

        $dnskeys = [$rootDnskey];
        for ($i = 0; $i < 8; $i++) {
            $dnskeys[] = new DNSKEYRecord('.', Duration::seconds(3600), 256, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        }

        $signedData = self::buildDnskeySignedData($dnskeys, $rootKeyTag, $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $signature,
        );

        $inner = self::createDnskeyResolver($dnskeys, $rrsig);
        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('.');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::ResourceExhaustion, $result->failure);
    }

    public function testEnforceRecordLimitsAllowsNineDnskeysWhenOnlyOneIsDnskey(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $signedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $signature = null;
        openssl_sign($signedData, $signature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $signature,
        );

        $inner = self::createDnskeyResolver([$rootDnskey], $rrsig);
        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('.');

        static::assertSame(TrustChainStatus::Secure, $result->status);
    }

    public function testDsNonExistenceWithNsecNotCoveringQueriedZoneReturnsBogus(): void
    {
        [$rootPrivateKey, $rootRfc3110Key] = self::generateRsaKeyPair();
        $rootDnskey = new DNSKEYRecord('.', Duration::seconds(3600), 257, 3, Algorithm::RSASHA256, $rootRfc3110Key);
        $rootKeyTag = KeyTag::compute($rootDnskey);

        $ownerWire = Encoder::encodeName('');
        $dnskeyRdata = new Writer()
            ->u16(257)
            ->u8(3)
            ->u8(8)
            ->bytes($rootRfc3110Key)
            ->toString();
        $digestHex = hash('sha256', $ownerWire . $dnskeyRdata);

        $anchor = new TrustAnchor([
            new DSRecord(
                '.',
                Duration::seconds(0),
                $rootKeyTag,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                $digestHex,
            ),
        ]);

        $now = Timestamp::now()->getSeconds();
        $expiration = $now + 86_400;
        $inception = $now - 86_400;
        $rootSignedData = self::buildDnskeySignedData([$rootDnskey], $rootKeyTag, $expiration, $inception);
        $rootSignature = null;
        openssl_sign($rootSignedData, $rootSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $rootRrsig = new RRSIGRecord(
            '.',
            Duration::seconds(3600),
            RecordType::DNSKEY,
            Algorithm::RSASHA256,
            0,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $rootSignature,
        );

        $nsec = new NSECRecord('com', Duration::seconds(3600), 'edu', [
            RecordType::NS,
            RecordType::SOA,
            RecordType::RRSIG,
            RecordType::NSEC,
        ]);

        $nsecSignedData = self::buildNsecSignedData($nsec, $rootKeyTag, $expiration, $inception);
        $nsecSignature = null;
        openssl_sign($nsecSignedData, $nsecSignature, $rootPrivateKey, OPENSSL_ALGO_SHA256);

        $nsecRrsig = new RRSIGRecord(
            'com',
            Duration::seconds(3600),
            RecordType::NSEC,
            Algorithm::RSASHA256,
            1,
            3600,
            $expiration,
            $inception,
            $rootKeyTag,
            '',
            $nsecSignature,
        );

        $inner = new class($rootDnskey, $rootRrsig, $nsec, $nsecRrsig) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly DNSKEYRecord $rootDnskey,
                private readonly RRSIGRecord $rootRrsig,
                private readonly NSECRecord $nsec,
                private readonly RRSIGRecord $nsecRrsig,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                // @mago-expect lint:no-shorthand-ternary
                $name = rtrim($name, '.') ?: '.';
                if ($name === '.' && $type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [$this->rootDnskey, $this->rootRrsig], [], []);
                }

                if ($type === RecordType::DS) {
                    return new Response(1, ResponseCode::NoError, [], [$this->nsec, $this->nsecRrsig], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };

        $resolver = new TrustChainResolver($inner, $anchor);

        $result = $resolver->resolve('net');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::MissingDs, $result->failure);
    }

    private static function createEmptyResolver(): ResolverInterface
    {
        return new class() implements ResolverInterface {
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
    }

    private static function createResolverWithDnskeyButNoRrsig(): ResolverInterface
    {
        return new class() implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                if ($type === RecordType::DNSKEY) {
                    $dnskey = new DNSKEYRecord(
                        '.',
                        Duration::seconds(3600),
                        257,
                        3,
                        Algorithm::RSASHA256,
                        "\x03\x01\x00\x01\xAB\xAB\xAB\xAB",
                    );

                    return new Response(1, ResponseCode::NoError, [$dnskey], [], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };
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
     * @param list<DNSKEYRecord> $dnskeys
     */
    private static function createDnskeyResolver(array $dnskeys, RRSIGRecord $rrsig): ResolverInterface
    {
        return new class($dnskeys, $rrsig) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            /** @param list<DNSKEYRecord> $dnskeys */
            public function __construct(
                private readonly array $dnskeys,
                private readonly RRSIGRecord $rrsig,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                if ($type === RecordType::DNSKEY) {
                    return new Response(1, ResponseCode::NoError, [...$this->dnskeys, $this->rrsig], [], []);
                }

                return new Response(1, ResponseCode::NoError, [], [], []);
            }
        };
    }

    /**
     * @param list<DNSKEYRecord> $dnskeys
     */
    private static function buildDnskeySignedData(array $dnskeys, int $keyTag, int $expiration, int $inception): string
    {
        $signerWire = Encoder::encodeName('');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::DNSKEY->value)
            ->u8(8)
            ->u8(0)
            ->u32(3600)
            ->u32($expiration)
            ->u32($inception)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $canonicalRrs = [];
        foreach ($dnskeys as $dnskey) {
            $ownerWire = Encoder::encodeName('.');
            $rdata = new Writer()
                ->u16($dnskey->flags)
                ->u8($dnskey->protocol)
                ->u8($dnskey->algorithm->value)
                ->bytes($dnskey->publicKey)
                ->toString();

            $canonicalRrs[] = new Writer()
                ->bytes($ownerWire)
                ->u16(RecordType::DNSKEY->value)
                ->u16(1)
                ->u32(3600)
                ->u16(Byte\length($rdata))
                ->bytes($rdata)
                ->toString();
        }

        $canonicalRrs = Vec\sort($canonicalRrs);

        return $rrsigPrefix . Str\join($canonicalRrs, '');
    }

    private static function buildNsecSignedData(NSECRecord $nsec, int $keyTag, int $expiration, int $inception): string
    {
        $signerWire = Encoder::encodeName('');
        $rrsigPrefix = new Writer()
            ->u16(RecordType::NSEC->value)
            ->u8(8)
            ->u8(1)
            ->u32(3600)
            ->u32($expiration)
            ->u32($inception)
            ->u16($keyTag)
            ->bytes($signerWire)
            ->toString();

        $ownerWire = Encoder::encodeName(Byte\lowercase($nsec->name));
        $rdata = Encoder::encodeName(Byte\lowercase($nsec->nextDomainName)) . TypeBitmap::encode($nsec->types);

        $canonicalRr = new Writer()
            ->bytes($ownerWire)
            ->u16(RecordType::NSEC->value)
            ->u16(1)
            ->u32(3600)
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
}
