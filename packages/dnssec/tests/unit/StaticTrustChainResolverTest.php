<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNSSEC\ChainFailure;
use Psl\DNSSEC\StaticTrustChainResolver;
use Psl\DNSSEC\TrustChainStatus;

final class StaticTrustChainResolverTest extends TestCase
{
    public function testResolveUsesNearestConfiguredAncestor(): void
    {
        $parent = new DNSKEYRecord('example.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'parent');
        $child = new DNSKEYRecord('child.example.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'child');
        $resolver = new StaticTrustChainResolver([
            'example.com.' => [$parent],
            'child.example.com' => [$child],
        ], ['unsigned.example.com']);

        static::assertSame([$parent], $resolver->resolve('WWW.EXAMPLE.COM.')->keys);
        static::assertSame([$child], $resolver->resolve('www.child.example.com')->keys);
        static::assertSame(TrustChainStatus::Insecure, $resolver->resolve('www.unsigned.example.com')->status);
        static::assertSame(TrustChainStatus::Bogus, $resolver->resolve('notexample.com')->status);
    }

    public function testResolveReturnsKeysForConfiguredZone(): void
    {
        $key = new DNSKEYRecord('.', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'publickey');
        $resolver = new StaticTrustChainResolver(['' => [$key]]);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertSame([$key], $result->keys);
    }

    public function testResolveReturnsMultipleKeys(): void
    {
        $ksk = new DNSKEYRecord('example.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'ksk-key');
        $zsk = new DNSKEYRecord('example.com', Duration::zero(), 256, 3, Algorithm::RSASHA256, 'zsk-key');
        $resolver = new StaticTrustChainResolver(['example.com' => [$ksk, $zsk]]);

        $result = $resolver->resolve('example.com');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertSame([$ksk, $zsk], $result->keys);
    }

    public function testResolveThrowsForUnconfiguredZone(): void
    {
        $resolver = new StaticTrustChainResolver([]);

        $result = $resolver->resolve('example.com');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::ChainBroken, $result->failure);
    }

    public function testResolveThrowsForUnconfiguredRootZone(): void
    {
        $resolver = new StaticTrustChainResolver([]);

        $result = $resolver->resolve('');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::ChainBroken, $result->failure);
    }

    public function testResolveIsCaseInsensitive(): void
    {
        $key = new DNSKEYRecord('example.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'publickey');
        $resolver = new StaticTrustChainResolver(['Example.COM' => [$key]]);

        static::assertSame([$key], $resolver->resolve('example.com')->keys);
        static::assertSame([$key], $resolver->resolve('EXAMPLE.COM')->keys);
        static::assertSame([$key], $resolver->resolve('Example.Com')->keys);
    }

    public function testResolveMultipleZones(): void
    {
        $rootKey = new DNSKEYRecord('.', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'root-key');
        $comKey = new DNSKEYRecord('com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'com-key');
        $exampleKey = new DNSKEYRecord('example.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'example-key');

        $resolver = new StaticTrustChainResolver([
            '' => [$rootKey],
            'com' => [$comKey],
            'example.com' => [$exampleKey],
        ]);

        static::assertSame([$rootKey], $resolver->resolve('')->keys);
        static::assertSame([$comKey], $resolver->resolve('com')->keys);
        static::assertSame([$exampleKey], $resolver->resolve('example.com')->keys);
    }

    public function testResolveReturnsEmptyListWhenConfiguredWithEmptyList(): void
    {
        $resolver = new StaticTrustChainResolver(['example.com' => []]);

        $result = $resolver->resolve('example.com');

        static::assertSame(TrustChainStatus::Secure, $result->status);
        static::assertSame([], $result->keys);
    }

    public function testExceptionMessageContainsZoneName(): void
    {
        $resolver = new StaticTrustChainResolver([]);

        $result = $resolver->resolve('sub.example.com');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::ChainBroken, $result->failure);
    }

    public function testExceptionPreservesOriginalCaseInMessage(): void
    {
        $resolver = new StaticTrustChainResolver([]);

        $result = $resolver->resolve('Example.COM');

        static::assertSame(TrustChainStatus::Bogus, $result->status);
        static::assertSame(ChainFailure::ChainBroken, $result->failure);
    }

    public function testResolveReturnsInsecureForConfiguredInsecureZone(): void
    {
        $resolver = new StaticTrustChainResolver([], ['insecure.example.com']);

        $result = $resolver->resolve('insecure.example.com');

        static::assertSame(TrustChainStatus::Insecure, $result->status);
        static::assertSame([], $result->keys);
    }

    public function testInsecureZoneIsCaseInsensitive(): void
    {
        $resolver = new StaticTrustChainResolver([], ['Insecure.COM']);

        $result = $resolver->resolve('insecure.com');

        static::assertSame(TrustChainStatus::Insecure, $result->status);
    }

    public function testInsecureZoneTakesPriorityOverKeys(): void
    {
        $key = new DNSKEYRecord('example.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'publickey');
        $resolver = new StaticTrustChainResolver(['example.com' => [$key]], ['example.com']);

        $result = $resolver->resolve('example.com');

        static::assertSame(TrustChainStatus::Insecure, $result->status);
        static::assertSame([], $result->keys);
    }

    public function testInsecureZoneIsRecognizedCorrectly(): void
    {
        $resolver = new StaticTrustChainResolver([], ['zone1.example.com', 'zone2.example.com']);

        $r1 = $resolver->resolve('zone1.example.com');
        $r2 = $resolver->resolve('zone2.example.com');
        $r3 = $resolver->resolve('zone3.example.com');

        static::assertSame(TrustChainStatus::Insecure, $r1->status);
        static::assertSame(TrustChainStatus::Insecure, $r2->status);
        static::assertSame(TrustChainStatus::Bogus, $r3->status, 'Non-configured zone should be Bogus');
    }

    public function testInsecureZoneWithSingleEntryReturnsInsecure(): void
    {
        $resolver = new StaticTrustChainResolver([], ['test.example.com']);

        $result = $resolver->resolve('test.example.com');

        static::assertSame(TrustChainStatus::Insecure, $result->status);
        static::assertNull($result->failure);
    }

    public function testInsecureZoneReturnsEmptyKeys(): void
    {
        $resolver = new StaticTrustChainResolver([], ['zone.test']);

        $result = $resolver->resolve('zone.test');

        static::assertSame(TrustChainStatus::Insecure, $result->status);
        static::assertSame([], $result->keys);
    }

    public function testInsecureZoneStoredValueAllowsIssetDetection(): void
    {
        $key = new DNSKEYRecord('other.com', Duration::zero(), 257, 3, Algorithm::RSASHA256, 'key');
        $resolver = new StaticTrustChainResolver(['other.com' => [$key]], ['insecure.com']);

        $insecureResult = $resolver->resolve('insecure.com');
        $secureResult = $resolver->resolve('other.com');
        $bogusResult = $resolver->resolve('missing.com');

        static::assertSame(TrustChainStatus::Insecure, $insecureResult->status);
        static::assertSame(TrustChainStatus::Secure, $secureResult->status);
        static::assertSame(TrustChainStatus::Bogus, $bogusResult->status);
    }
}
