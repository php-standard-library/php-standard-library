<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Cache\Exception\UnavailableItemException;
use Psl\Cache\LocalStore;
use Psl\Cache\NullStore;
use Psl\Cache\StoreInterface;
use Psl\DateTime\Duration;
use Psl\DNS\CachedResolver;
use Psl\DNS\EDNS;
use Psl\DNS\Record\AAAARecord;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\NSRecord;
use Psl\DNS\Record\PTRRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\SOARecord;
use Psl\DNS\ResolverConvenienceMethodsTrait;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\DNS\StaticResolver;
use Psl\IP\Address;
use ReflectionProperty;
use RuntimeException;

use function array_fill;
use function array_map;
use function max;
use function md5;
use function serialize;

final class CachedResolverTest extends TestCase
{
    public function testCachesSuccessfulResponse(): void
    {
        $inner = new StaticResolver([
            'example.com' => [
                RecordType::A->value => [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
            ],
        ]);

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $response1 = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response1->code);
        static::assertCount(1, $response1->answers);
        $response2 = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $response2->code);
        static::assertCount(1, $response2->answers);
    }

    public function testDelegatesToInnerOnCacheMiss(): void
    {
        $inner = new StaticResolver([]);

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $response = $resolver->query('unknown.example.com', RecordType::A);
        static::assertSame(ResponseCode::NonExistentDomain, $response->code);
    }

    public function testDifferentQueriesGetDifferentCacheEntries(): void
    {
        $inner = new StaticResolver([
            'example.com' => [
                RecordType::A->value => [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
            ],
        ]);

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $responseA = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $responseA->code);
        static::assertCount(1, $responseA->answers);

        $responseAAAA = $resolver->query('example.com', RecordType::AAAA);
        static::assertSame(ResponseCode::NoError, $responseAAAA->code);
        static::assertCount(0, $responseAAAA->answers);
    }

    public function testReverseQueryDelegatesToQuery(): void
    {
        $ip = Address::v4('10.0.0.1');
        $inner = new StaticResolver([
            $ip->toArpaName() => [
                RecordType::PTR->value => [
                    new PTRRecord($ip->toArpaName(), Duration::seconds(300), 'example.com'),
                ],
            ],
        ]);

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $response = $resolver->reverseQuery($ip);
        static::assertSame(ResponseCode::NoError, $response->code);
        static::assertCount(1, $response->answers);
    }

    public function testServerFailureResponseIsCachedBriefly(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(0, ResponseCode::ServerFailure, [], [], []);
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('fail.example.com', RecordType::A);
        $resolver->query('fail.example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testNoErrorResponseIsCachedWithTtl(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('example.com', RecordType::A);
        $r2 = $resolver->query('example.com', RecordType::A);

        static::assertSame(1, $callCount);
        static::assertSame(ResponseCode::NoError, $r1->code);
        static::assertSame(ResponseCode::NoError, $r2->code);
    }

    public function testNonExistentDomainResponseIsCachedWithTtl(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [
                    self::soa('example.com', Duration::seconds(120), Duration::seconds(120)),
                ],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('nx.example.com', RecordType::A);
        $r2 = $resolver->query('nx.example.com', RecordType::A);

        static::assertSame(1, $callCount);
        static::assertSame(ResponseCode::NonExistentDomain, $r1->code);
        static::assertSame(ResponseCode::NonExistentDomain, $r2->code);
    }

    public function testZeroTtlRecordIsNotCached(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::zero(), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::A);

        static::assertSame(2, $callCount);
    }

    public function testBuildKeyCaseInsensitive(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('Example.COM', RecordType::A);
        $resolver->query('example.com', RecordType::A);
        $resolver->query('EXAMPLE.COM', RecordType::A);

        static::assertSame(1, $callCount, 'All case variants should hit the same cache key');
    }

    public function testBuildKeyIncludesRecordType(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::AAAA);

        static::assertSame(2, $callCount, 'Different record types should be different cache keys');
    }

    public function testEdnsOptionsAffectCacheKey(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::A, ednsOptions: [new EDNS\NSIDOption()]);

        static::assertSame(2, $callCount, 'EDNS options should create a different cache key');
    }

    public function testExtractMinTtlUsesAuthorityWhenNoAnswers(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [
                    self::soa('example.com', Duration::seconds(60), Duration::seconds(60)),
                ],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('nx.example.com', RecordType::A);
        static::assertSame(ResponseCode::NonExistentDomain, $r1->code);
        static::assertSame(1, $callCount);
    }

    public function testExtractMinTtlUsesAnswersOverAuthority(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(200), Address::v4('10.0.0.1')),
                ],
                [
                    new NSRecord('example.com', Duration::seconds(10), 'ns1.example.com'),
                ],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('example.com', RecordType::A);
        static::assertSame(ResponseCode::NoError, $r1->code);
        static::assertCount(1, $r1->answers);
    }

    public function testNoErrorResponseWithPositiveTtlUpdatesCacheEntry(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(120), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testServerFailureIsNotCachedWithRecordTtl(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(0, ResponseCode::ServerFailure, [], [], []);
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('fail.example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testExtractMinTtlReturns60WhenNoRecords(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(0, ResponseCode::NoError, [], [], []);
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('empty.example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testExtractMinTtlPrefersAnswersOverAuthority(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(100), Address::v4('10.0.0.1')),
                ],
                [
                    new NSRecord('example.com', Duration::seconds(5), 'ns1.example.com'),
                ],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('example.com', RecordType::A);
        $r2 = $resolver->query('example.com', RecordType::A);

        static::assertSame(1, $callCount);
        static::assertCount(1, $r1->answers);
        static::assertCount(1, $r2->answers);
    }

    public function testCacheKeyIsSeparatedByColon(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('a1.example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('a1.example.com', RecordType::A);
        $resolver->query('a1.example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testBuildKeyEdnsOptionsAppendHashWithSeparator(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $option = new EDNS\NSIDOption();
        $resolver->query('example.com', RecordType::A, ednsOptions: [$option]);
        $resolver->query('example.com', RecordType::A, ednsOptions: [$option]);

        static::assertSame(1, $callCount);
    }

    public function testNoEdnsOptionsDoesNotAppendHash(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1')),
                ],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A);

        $resolver->query('example.com', RecordType::A, ednsOptions: [new EDNS\NSIDOption()]);

        static::assertSame(2, $callCount);
    }

    public function testNonExistentDomainWithAuthorityRecordsCaches(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [
                    self::soa('example.com', Duration::seconds(120), Duration::seconds(120)),
                    self::soa('example.com', Duration::seconds(60), Duration::seconds(60)),
                ],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('nx.example.com', RecordType::A);
        $r2 = $resolver->query('nx.example.com', RecordType::A);

        static::assertSame(1, $callCount);
        static::assertSame(ResponseCode::NonExistentDomain, $r1->code);
        static::assertSame(ResponseCode::NonExistentDomain, $r2->code);
    }

    public function testNoErrorAndServerFailureUseSeparateEntries(): void
    {
        $callCountNoError = 0;
        $innerNoError = self::countingResolver(static function () use (&$callCountNoError): Response {
            $callCountNoError++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('ok.example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $callCountFail = 0;
        $innerFail = self::countingResolver(static function () use (&$callCountFail): Response {
            $callCountFail++;
            return new Response(0, ResponseCode::ServerFailure, [], [], []);
        });

        $cacheOk = new LocalStore();
        $resolverOk = new CachedResolver($innerNoError, $cacheOk);
        $resolverOk->query('ok.example.com', RecordType::A);
        $resolverOk->query('ok.example.com', RecordType::A);
        static::assertSame(1, $callCountNoError);

        $cacheFail = new LocalStore();
        $resolverFail = new CachedResolver($innerFail, $cacheFail);
        $resolverFail->query('fail.example.com', RecordType::A);
        static::assertSame(1, $callCountFail);
    }

    public function testDifferentDomainsAreDifferentCacheEntries(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('test.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('alpha.example.com', RecordType::A);
        $resolver->query('beta.example.com', RecordType::A);

        static::assertSame(2, $callCount);
    }

    public function testSameDomainDifferentTypesAreDifferentEntries(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::MX);

        static::assertSame(2, $callCount);
    }

    public function testNxdomainWithoutSoaIsNotCached(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(0, ResponseCode::NonExistentDomain, [], [], []);
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('nx.example.com', RecordType::A);
        $r2 = $resolver->query('nx.example.com', RecordType::A);

        static::assertSame(2, $callCount);
        static::assertSame(ResponseCode::NonExistentDomain, $r1->code);
        static::assertSame(ResponseCode::NonExistentDomain, $r2->code);
    }

    public function testSuccessAndFailureQueriesRemainSeparate(): void
    {
        $serverFailCallCount = 0;
        $innerFail = self::countingResolver(static function () use (&$serverFailCallCount): Response {
            $serverFailCallCount++;
            return new Response(0, ResponseCode::ServerFailure, [], [], []);
        });

        $cacheFail = new LocalStore();
        $resolverFail = new CachedResolver($innerFail, $cacheFail);
        $resolverFail->query('sf.example.com', RecordType::A);
        static::assertSame(1, $serverFailCallCount);

        $noErrorCallCount = 0;
        $innerNoError = self::countingResolver(static function () use (&$noErrorCallCount): Response {
            $noErrorCallCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('ok.example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cacheOk = new LocalStore();
        $resolverOk = new CachedResolver($innerNoError, $cacheOk);
        $resolverOk->query('ok.example.com', RecordType::A);
        $resolverOk->query('ok.example.com', RecordType::A);
        static::assertSame(1, $noErrorCallCount);
    }

    public function testAuthorityRecordsUsedForNxdomainTtl(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [self::soa('example.com', Duration::seconds(300), Duration::seconds(300))],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('nx.example.com', RecordType::A);
        $r2 = $resolver->query('nx.example.com', RecordType::A);

        static::assertSame(1, $callCount);
        static::assertSame(ResponseCode::NonExistentDomain, $r1->code);
        static::assertSame(ResponseCode::NonExistentDomain, $r2->code);
        static::assertCount(1, $r1->authority);
    }

    public function testNodataWithoutSoaIsNotStored(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(static fn(): Response => new Response(0, ResponseCode::NoError, [], [], []));

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('empty.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testNodataWithoutSoaDoesNotUseDefaultTtl(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(static fn(): Response => new Response(0, ResponseCode::NoError, [], [], []));

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('empty.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testNxdomainWithoutSoaDoesNotUseDefaultTtl(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::NonExistentDomain, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('nx.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testAuthorityTtlIsUsedWhenNoAnswersPresent(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [
                    self::soa('example.com', Duration::seconds(45), Duration::seconds(45)),
                    self::soa('example.com', Duration::seconds(90), Duration::seconds(90)),
                ],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('nx.example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(45, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testAuthorityTtlSetsHasRecordsFlagCorrectly(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [
                    self::soa('example.com', Duration::seconds(200), Duration::seconds(200)),
                ],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('nx.example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(200, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testAuthorityNotUsedWhenAnswersExist(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(100), Address::v4('10.0.0.1')),
                ],
                [
                    new NSRecord('example.com', Duration::seconds(5), 'ns1.example.com'),
                ],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(100, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testMultipleAuthorityRecordsUsesMinimumTtl(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [
                    self::soa('example.com', Duration::seconds(500), Duration::seconds(500)),
                    self::soa('example.com', Duration::seconds(120), Duration::seconds(120)),
                    self::soa('example.com', Duration::seconds(300), Duration::seconds(300)),
                ],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('nx.example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(120, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testNoErrorResponseTriggersTtlExtraction(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('ok.example.com', Duration::seconds(60), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('ok.example.com', RecordType::A);
        $resolver->query('ok.example.com', RecordType::A);
        $resolver->query('ok.example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testNxdomainResponseTriggersTtlExtraction(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [self::soa('example.com', Duration::seconds(60), Duration::seconds(60))],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('nx2.example.com', RecordType::A);
        $resolver->query('nx2.example.com', RecordType::A);
        $resolver->query('nx2.example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testServerFailureUsesDefaultFailureTtl(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::ServerFailure, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('sf.example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(5.0, $updateCalls[0]['ttl']?->getTotalSeconds());
    }

    public function testFormatErrorDoesNotTriggerTtlUpdate(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::FormatError, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('ferr.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testRefusedUsesDefaultFailureTtl(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::ServerRefused, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('refused.example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(5.0, $updateCalls[0]['ttl']?->getTotalSeconds());
    }

    public function testNoErrorTriggersTtlUpdate(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('ok.test', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('ok.test', RecordType::A);

        static::assertCount(1, $updateCalls);
    }

    public function testNxdomainTriggersUpdateWithAuthorityTtl(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [self::soa('example.com', Duration::seconds(180), Duration::seconds(180))],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('nx.test', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(180, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testZeroTtlAnswerRecordDoesNotTriggerUpdate(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('zero.example.com', Duration::zero(), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('zero.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testZeroTtlAuthorityRecordDoesNotTriggerUpdate(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [self::soa('example.com', Duration::zero(), Duration::zero())],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('zero-auth.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testPositiveTtlCachesResponseForSubsequentCalls(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('cached.example.com', Duration::seconds(600), Address::v4('10.0.0.2'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $r1 = $resolver->query('cached.example.com', RecordType::A);
        $r2 = $resolver->query('cached.example.com', RecordType::A);
        $r3 = $resolver->query('cached.example.com', RecordType::A);

        static::assertSame(1, $callCount);
        static::assertSame($r1->code, $r2->code);
        static::assertSame($r1->code, $r3->code);
        static::assertCount(1, $r1->answers);
        static::assertCount(1, $r2->answers);
        static::assertCount(1, $r3->answers);
    }

    public function testCacheKeyColonSeparatorPreventsAmbiguity(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('test.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('abc', RecordType::A);
        $resolver->query('abc', RecordType::AAAA);
        $resolver->query('abc', RecordType::MX);

        static::assertSame(3, $callCount);
    }

    public function testEdnsOptionsAppendSeparatorAndHashToKey(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('edns.example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('edns.example.com', RecordType::A, ednsOptions: []);
        static::assertSame(1, $callCount);

        $resolver->query('edns.example.com', RecordType::A, ednsOptions: [new EDNS\NSIDOption()]);
        static::assertSame(2, $callCount);

        $resolver->query('edns.example.com', RecordType::A, ednsOptions: [new EDNS\NSIDOption()]);
        static::assertSame(2, $callCount);
    }

    public function testEmptyEdnsOptionsDoNotModifyKey(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A, ednsOptions: []);
        $resolver->query('example.com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testDifferentEdnsOptionsCreateDifferentKeys(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('example.com', RecordType::A, ednsOptions: [new EDNS\NSIDOption()]);
        $resolver->query('example.com', RecordType::A, ednsOptions: [new EDNS\NSIDOption('server1')]);

        static::assertSame(2, $callCount);
    }

    public function testExtractMinTtlAnswerRecordsPresentDoNotFallToAuthority(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [
                    new ARecord('example.com', Duration::seconds(100), Address::v4('10.0.0.1')),
                    new ARecord('example.com', Duration::seconds(200), Address::v4('10.0.0.2')),
                ],
                [
                    new NSRecord('example.com', Duration::seconds(5), 'ns1.example.com'),
                ],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame(100, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testComputeUsesZeroTtlUntilResponseIsKnown(): void
    {
        $computeTtls = [];
        $store = self::createComputeTrackingCacheStore($computeTtls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('example.com', RecordType::A);

        static::assertCount(1, $computeTtls);
        static::assertNotNull($computeTtls[0]);
        static::assertSame(0, (int) $computeTtls[0]->getTotalSeconds());
    }

    public function testFailureComputeUsesZeroTtl(): void
    {
        $computeTtls = [];
        $store = self::createComputeTrackingCacheStore($computeTtls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::ServerFailure, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('sf.example.com', RecordType::A);

        static::assertCount(1, $computeTtls);
        static::assertNotNull($computeTtls[0]);
        static::assertSame(0, (int) $computeTtls[0]->getTotalSeconds());
    }

    public function testComputeDoesNotExposeUnboundedResponse(): void
    {
        $computeTtls = [];
        $store = self::createComputeTrackingCacheStore($computeTtls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(120), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('example.com', RecordType::A);

        static::assertCount(1, $computeTtls);
        static::assertNotNull($computeTtls[0]);
        static::assertGreaterThanOrEqual(0, (int) $computeTtls[0]->getTotalSeconds());
        static::assertLessThanOrEqual(0, (int) $computeTtls[0]->getTotalSeconds());
    }

    public function testUpdateIsCalledWhenTtlValueIsNotNull(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(120), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertNotNull($updateCalls[0]['ttl']);
        static::assertSame(120, (int) $updateCalls[0]['ttl']->getTotalSeconds());
    }

    public function testUpdateNotCalledWhenTtlValueIsNull(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::FormatError, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('sf.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testCacheKeyIsLowercaseWithColonSeparator(): void
    {
        $callCount = 0;
        $inner = self::countingResolver(static function () use (&$callCount): Response {
            $callCount++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('case.example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });

        $cache = new LocalStore();
        $resolver = new CachedResolver($inner, $cache);

        $resolver->query('CASE.EXAMPLE.COM', RecordType::A);
        $resolver->query('case.example.com', RecordType::A);
        $resolver->query('Case.Example.Com', RecordType::A);

        static::assertSame(1, $callCount);
    }

    public function testSuccessNegativeAndServerFailureTriggerUpdates(): void
    {
        $noErrUpdates = [];
        $storeNoErr = self::createTrackingUpdateCacheStore($noErrUpdates);
        $innerNoErr = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('ok.test', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );
        $resolverNoErr = new CachedResolver($innerNoErr, $storeNoErr);
        $resolverNoErr->query('ok.test', RecordType::A);
        static::assertCount(1, $noErrUpdates);

        $nxUpdates = [];
        $storeNx = self::createTrackingUpdateCacheStore($nxUpdates);
        $innerNx = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NonExistentDomain,
                [],
                [self::soa('test', Duration::seconds(60), Duration::seconds(60))],
                [],
            ),
        );
        $resolverNx = new CachedResolver($innerNx, $storeNx);
        $resolverNx->query('nx.test', RecordType::A);
        static::assertCount(1, $nxUpdates);

        $sfUpdates = [];
        $storeSf = self::createTrackingUpdateCacheStore($sfUpdates);
        $innerSf = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::ServerFailure, [], [], []),
        );
        $resolverSf = new CachedResolver($innerSf, $storeSf);
        $resolverSf->query('sf.test', RecordType::A);
        static::assertCount(1, $sfUpdates);
        static::assertSame(5.0, $sfUpdates[0]['ttl']?->getTotalSeconds());
    }

    public function testUpdateKeyMatchesComputeKey(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('verify-key.example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('verify-key.example.com', RecordType::A);

        static::assertCount(1, $updateCalls);
        static::assertSame('verify-key.example.com:1', $updateCalls[0]['key']);
    }

    public function testUpdateKeyWithEdnsContainsHashSuffix(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('edns-key.example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            ),
        );

        $resolver = new CachedResolver($inner, $store);
        $option = new EDNS\NSIDOption();
        $resolver->query('edns-key.example.com', RecordType::A, ednsOptions: [$option]);

        static::assertCount(1, $updateCalls);
        $key = $updateCalls[0]['key'];
        static::assertStringStartsWith('edns-key.example.com:1:', $key);
        static::assertSame('edns-key.example.com:1:' . md5(serialize([$option])), $key);
    }

    public function testNotImplementedDoesNotTriggerUpdate(): void
    {
        $updateCalls = [];
        $store = self::createTrackingUpdateCacheStore($updateCalls);

        $inner = self::countingResolver(
            static fn(): Response => new Response(0, ResponseCode::NotImplemented, [], [], []),
        );

        $resolver = new CachedResolver($inner, $store);
        $resolver->query('notimp.example.com', RecordType::A);

        static::assertCount(0, $updateCalls);
    }

    public function testConcurrentQueriesReusePositiveResponse(): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;
            Async\sleep(Duration::milliseconds(10));
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });
        $resolver = new CachedResolver($inner, new LocalStore());

        [$first, $second] = Async\concurrently([
            static fn(): Response => $resolver->query('example.com', RecordType::A),
            static fn(): Response => $resolver->query('example.com', RecordType::A),
        ]);

        static::assertSame(1, $calls);
        static::assertEquals($first, $second);
    }

    public function testConcurrentQueriesReusePositiveResponseFromAStoreServingCallsInArrivalOrder(): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;
            Async\sleep(Duration::milliseconds(10));
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });
        $resolver = new CachedResolver($inner, self::arrivalOrderStore(new LocalStore()));

        [$first, $second] = Async\concurrently([
            static fn(): Response => $resolver->query('example.com', RecordType::A),
            static fn(): Response => $resolver->query('example.com', RecordType::A),
        ]);

        static::assertSame(1, $calls);
        static::assertEquals($first, $second);
    }

    /**
     * Stores that serialize the calls for each key, as `StoreInterface` requires.
     *
     * @return iterable<string, array{Closure(): StoreInterface}>
     */
    public static function storesSerializingCalls(): iterable
    {
        yield 'LocalStore' => [static fn(): StoreInterface => new LocalStore()];
        yield 'arrival order' => [static fn(): StoreInterface => self::arrivalOrderStore(new LocalStore())];
        yield 'one turn per call' => [static fn(): StoreInterface => self::oneTurnPerCallStore(new LocalStore())];
        yield 'one turn after each call' => [
            static fn(): StoreInterface => self::oneTurnAfterEachCallStore(new LocalStore()),
        ];
    }

    /**
     * @param Closure(): StoreInterface $store
     */
    #[DataProvider('storesSerializingCalls')]
    public function testManyConcurrentQueriesReusePositiveResponse(Closure $store): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;
            Async\sleep(Duration::milliseconds(10));
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });
        $resolver = new CachedResolver($inner, $store());

        $responses = Async\concurrently(array_fill(0, 5, static fn(): Response => $resolver->query(
            'example.com',
            RecordType::A,
        )));

        static::assertSame(1, $calls);
        static::assertCount(5, $responses);
        foreach ($responses as $response) {
            static::assertEquals($responses[0], $response);
        }
    }

    /**
     * Two resolvers that share one store reuse each other's lookups: a query of one that the store serves
     * while a lookup of the other is giving its entry its TTL gets that entry, so concurrent queries through
     * both for the same question make one inner query.
     *
     * @param Closure(): StoreInterface $store
     */
    #[DataProvider('storesSerializingCalls')]
    public function testConcurrentQueriesOfResolversSharingAStoreReusePositiveResponse(Closure $store): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;
            Async\sleep(Duration::milliseconds(10));
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });
        $shared = $store();
        $first = new CachedResolver($inner, $shared);
        $second = new CachedResolver($inner, $shared);

        $responses = Async\concurrently([
            static fn(): Response => $first->query('example.com', RecordType::A),
            static fn(): Response => $second->query('example.com', RecordType::A),
            static fn(): Response => $first->query('example.com', RecordType::A),
        ]);

        static::assertSame(1, $calls);
        foreach ($responses as $response) {
            static::assertEquals($responses[0], $response);
        }
    }

    /**
     * Concurrent queries for different questions each get the answer to their own question: a lookup reuses
     * only the entry kept for its own question, which the name, the record type and the EDNS options each
     * tell apart.
     *
     * @param Closure(): StoreInterface $store
     */
    #[DataProvider('storesSerializingCalls')]
    public function testConcurrentQueriesForDifferentQuestionsGetTheirOwnAnswers(Closure $store): void
    {
        $calls = 0;
        $resolver = new CachedResolver(self::questionAnsweringResolver($calls), $store());
        $ecs = [new EDNS\ECSOption(Address::v4('192.0.2.0'), 24, 0)];

        $responses = Async\concurrently([
            static fn(): Response => $resolver->query('example.com', RecordType::A),
            static fn(): Response => $resolver->query('example.com', RecordType::AAAA),
            static fn(): Response => $resolver->query('example.com', RecordType::A, ednsOptions: $ecs),
            static fn(): Response => $resolver->query('example.org', RecordType::A),
        ]);

        static::assertSame(4, $calls);
        static::assertSame(
            [
                'example.com A 10.0.0.1',
                'example.com AAAA 2001:db8::1',
                'example.com A 10.0.0.2',
                'example.org A 10.0.0.1',
            ],
            array_map(self::describeAnswer(...), $responses),
        );
    }

    public function testConcurrentQueriesWithANullStoreAskTheInnerResolverAtTheSameTime(): void
    {
        $gate = new Async\Deferred();
        $calls = 0;
        $resolver = new CachedResolver(self::gatedResolver($gate->getAwaitable(), $calls), new NullStore());

        $first = Async\run(static fn(): Response => $resolver->query('example.com', RecordType::A));
        $second = Async\run(static fn(): Response => $resolver->query('example.com', RecordType::A));
        Async\later();
        Async\later();

        static::assertSame(2, $calls);

        $gate->complete(null);
        static::assertEquals($first->await(), $second->await());
    }

    public function testCancelledQueryWithANullStoreDoesNotWaitForAnotherQuery(): void
    {
        $gate = new Async\Deferred();
        $calls = 0;
        $resolver = new CachedResolver(self::gatedResolver($gate->getAwaitable(), $calls), new NullStore());
        $token = new Async\SignalCancellationToken();

        $first = Async\run(static fn(): Response => $resolver->query('example.com', RecordType::A));
        $second = Async\run(static fn(): Response => $resolver->query('example.com', RecordType::A, $token));
        Async\later();
        $token->cancel();
        Async\later();
        Async\later();

        static::assertFalse($first->isComplete());
        static::assertTrue($second->isComplete());

        $gate->complete(null);
        $first->await();

        $this->expectException(CancelledException::class);
        $second->await();
    }

    /**
     * A query is cancelled while the inner resolver answers it, so it caches nothing. The queries that
     * waited for it in the store share the next lookup, instead of each asking the inner resolver in turn.
     *
     * @param Closure(): StoreInterface $store
     */
    #[DataProvider('storesSerializingCalls')]
    public function testQueriesWaitingBehindACancelledLookupShareTheNextLookup(Closure $store): void
    {
        $gate = new Async\Deferred();
        $calls = 0;
        $resolver = new CachedResolver(self::gatedResolver($gate->getAwaitable(), $calls), $store());
        $token = new Async\SignalCancellationToken();

        $cancelled = Async\run(static fn(): Response => $resolver->query('example.com', RecordType::A, $token));
        $cancelled->ignore();
        // Enough turns for the query to reach the inner resolver, even if each store call takes one first.
        Async\later();
        Async\later();
        Async\later();

        static::assertSame(1, $calls);

        $waiters = [];
        foreach (array_fill(0, 5, static fn(): Response => $resolver->query('example.com', RecordType::A)) as $query) {
            $waiters[] = Async\run($query);
        }

        Async\later();
        $token->cancel();
        $gate->complete(null);
        $responses = Async\all($waiters);

        // The cancelled query, and the lookup that the waiting queries share.
        static::assertSame(2, $calls);
        foreach ($responses as $response) {
            static::assertEquals($responses[0], $response);
        }

        $this->expectException(CancelledException::class);
        $cancelled->await();
    }

    /**
     * A response that is not cached is not shared either: each concurrent query asks the inner resolver,
     * one at a time, as the store serializes their calls.
     *
     * @param Closure(): StoreInterface $store
     */
    #[DataProvider('storesSerializingCalls')]
    public function testConcurrentQueriesDoNotReuseAResponseThatIsNotCached(Closure $store): void
    {
        $calls = 0;
        $running = 0;
        $mostRunning = 0;
        $inner = self::countingResolver(static function () use (&$calls, &$running, &$mostRunning): Response {
            $calls++;
            $running++;
            $mostRunning = max($mostRunning, $running);
            Async\later();
            $running--;

            return new Response(0, ResponseCode::FormatError, [], [], []);
        });
        $resolver = new CachedResolver($inner, $store());

        Async\concurrently(array_fill(0, 3, static fn(): Response => $resolver->query('example.com', RecordType::A)));

        static::assertSame(3, $calls);
        static::assertSame(1, $mostRunning);
    }

    /**
     * A lookup keeps the entry it computed only until it has given the entry its TTL, so a later query
     * with a store that keeps nothing asks the inner resolver again.
     */
    public function testQueriesWithANullStoreDoNotReuseAnEarlierLookup(): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;

            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });
        $resolver = new CachedResolver($inner, new NullStore());

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::A);

        static::assertSame(2, $calls);
    }

    /**
     * The store calls that may throw once the computer of a lookup has kept the entry it computed: the
     * call that computes the entry, after the store has run the computer, and the call that gives the
     * entry its TTL.
     *
     * @return iterable<string, array{'compute'|'update'}>
     */
    public static function storeCallsThrowingAfterTheComputer(): iterable
    {
        yield 'compute()' => ['compute'];
        yield 'update()' => ['update'];
    }

    /**
     * A store call throws once the computer of a lookup has kept the entry it computed. The query fails
     * with the store's exception and the lookup keeps nothing, so the next query asks the inner resolver
     * again instead of reusing an entry that never got its TTL.
     *
     * @param 'compute'|'update' $throwingCall
     */
    #[DataProvider('storeCallsThrowingAfterTheComputer')]
    public function testLookupKeepsNothingWhenAStoreCallThrows(string $throwingCall): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;

            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.' . $calls))],
                [],
                [],
            );
        });
        $resolver = new CachedResolver($inner, self::storeThrowingOnce(new LocalStore(), $throwingCall));
        $awaitingTtl = new ReflectionProperty(CachedResolver::class, 'awaitingTtl');

        try {
            $resolver->query('example.com', RecordType::A);
            static::fail('The store call did not throw.');
        } catch (RuntimeException $exception) {
            static::assertSame('The store is unavailable.', $exception->getMessage());
        }

        static::assertSame(1, $calls);
        static::assertSame([], $awaitingTtl->getValue($resolver)->value);

        $response = $resolver->query('example.com', RecordType::A);

        static::assertSame(2, $calls);
        static::assertInstanceOf(ARecord::class, $response->answers[0]);
        static::assertEquals(Address::v4('10.0.0.2'), $response->answers[0]->address);
        static::assertSame([], $awaitingTtl->getValue($resolver)->value);

        // The second lookup gave its entry a TTL, so the store serves the next query.
        $resolver->query('example.com', RecordType::A);
        static::assertSame(2, $calls);
    }

    /** @return iterable<string, array{ResponseCode}> */
    public static function failureCodes(): iterable
    {
        yield 'FORMERR' => [ResponseCode::FormatError];
        yield 'NOTIMP' => [ResponseCode::NotImplemented];
    }

    #[DataProvider('failureCodes')]
    public function testOtherFailureCodesAreNotReused(ResponseCode $code): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls, $code): Response {
            $calls++;
            return new Response(0, $code, [], [], []);
        });
        $resolver = new CachedResolver($inner, new LocalStore());

        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::A);

        static::assertSame(2, $calls);
    }

    /** @return iterable<string, array{ResponseCode, int, int, bool, int}> */
    public static function negativeTtls(): iterable
    {
        yield 'NXDOMAIN SOA MINIMUM' => [ResponseCode::NonExistentDomain, 300, 30, false, 30];
        yield 'NXDOMAIN SOA TTL' => [ResponseCode::NonExistentDomain, 30, 300, false, 30];
        yield 'NODATA SOA MINIMUM' => [ResponseCode::NoError, 300, 30, false, 30];
        yield 'NODATA SOA TTL' => [ResponseCode::NoError, 30, 300, false, 30];
        yield 'CNAME NODATA' => [ResponseCode::NoError, 300, 30, true, 30];
        yield 'CNAME NXDOMAIN' => [ResponseCode::NonExistentDomain, 300, 30, true, 30];
        yield 'CNAME TTL' => [ResponseCode::NonExistentDomain, 300, 300, true, 60];
        yield 'zero MINIMUM' => [ResponseCode::NonExistentDomain, 300, 0, false, 0];
        yield 'zero SOA TTL' => [ResponseCode::NoError, 0, 300, false, 0];
    }

    #[DataProvider('negativeTtls')]
    public function testNegativeTtlUsesSoa(
        ResponseCode $code,
        int $soaTtl,
        int $minimum,
        bool $withCname,
        int $expected,
    ): void {
        $updates = [];
        $store = self::createTrackingUpdateCacheStore($updates);
        $response = new Response(
            0,
            $code,
            $withCname ? [new CNAMERecord('example.com', Duration::seconds(60), 'missing.example.com')] : [],
            [self::soa('example.com', Duration::seconds($soaTtl), Duration::seconds($minimum))],
            [],
        );
        $resolver = new CachedResolver(self::countingResolver(static fn(): Response => $response), $store);

        $resolver->query('example.com', RecordType::A);

        static::assertCount($expected === 0 ? 0 : 1, $updates);
        if ($expected > 0) {
            static::assertSame($expected, (int) $updates[0]['ttl']->getTotalSeconds());
        }
    }

    public function testCookiesAndPaddingDoNotSplitCacheEntries(): void
    {
        $calls = 0;
        $inner = self::countingResolver(static function () use (&$calls): Response {
            $calls++;
            return new Response(
                0,
                ResponseCode::NoError,
                [new ARecord('example.com', Duration::seconds(300), Address::v4('10.0.0.1'))],
                [],
                [],
            );
        });
        $resolver = new CachedResolver($inner, new LocalStore());
        $ecs = new EDNS\ECSOption(Address::v4('192.0.2.0'), 24, 0);

        $resolver->query('example.com', RecordType::A, ednsOptions: [new EDNS\CookieOption('aaaaaaaa'), $ecs]);
        $resolver->query('example.com', RecordType::A, ednsOptions: [
            $ecs,
            new EDNS\CookieOption('bbbbbbbb'),
            new EDNS\PaddingOption(128),
        ]);
        $resolver->query('example.com', RecordType::A, ednsOptions: [$ecs]);
        static::assertSame(1, $calls);

        $resolver->query('example.com', RecordType::A, ednsOptions: [
            new EDNS\CookieOption('cccccccc'),
            new EDNS\PaddingOption(32),
        ]);
        $resolver->query('example.com', RecordType::A);
        static::assertSame(2, $calls);

        $resolver->query('example.com', RecordType::A, ednsOptions: [new EDNS\ECSOption(
            Address::v4('198.51.100.0'),
            24,
            0,
        )]);
        static::assertSame(3, $calls);
    }

    private static function soa(string $name, Duration $ttl, Duration $minimum): SOARecord
    {
        return new SOARecord(
            $name,
            $ttl,
            'ns1.' . $name,
            'hostmaster.' . $name,
            1,
            Duration::hours(1),
            Duration::minutes(5),
            Duration::hours(24),
            $minimum,
        );
    }

    /**
     * A store that serves one call at a time, in the order the calls arrive, whatever their key.
     */
    private static function arrivalOrderStore(StoreInterface $inner): StoreInterface
    {
        return new class($inner) implements StoreInterface {
            /**
             * The calls to the inner store, served one at a time in arrival order.
             *
             * @var Async\Sequence<Closure(): mixed, mixed>
             */
            private Async\Sequence $sequence;

            /**
             * Serve the calls to the given store one at a time.
             */
            public function __construct(
                /**
                 * The store that serves the calls.
                 */
                private readonly StoreInterface $inner,
            ) {
                $this->sequence = new Async\Sequence(static fn(Closure $call): mixed => $call());
            }

            /**
             * Get the value from the inner store once the calls before this one are served.
             */
            public function get(string $key): mixed
            {
                return $this->sequence->waitFor(fn(): mixed => $this->inner->get($key));
            }

            /**
             * Compute the value with the inner store once the calls before this one are served.
             */
            public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                return $this->sequence->waitFor(fn(): mixed => $this->inner->compute($key, $computer, $ttl));
            }

            /**
             * Update the value with the inner store once the calls before this one are served.
             */
            public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                return $this->sequence->waitFor(fn(): mixed => $this->inner->update($key, $computer, $ttl));
            }

            /**
             * Delete the value from the inner store once the calls before this one are served.
             */
            public function delete(string $key): void
            {
                $this->sequence->waitFor(fn(): null => $this->inner->delete($key));
            }
        };
    }

    /**
     * A store whose every call reaches the given store one event loop turn later, as the calls of a store
     * that does I/O would.
     */
    private static function oneTurnPerCallStore(StoreInterface $inner): StoreInterface
    {
        return new class($inner) implements StoreInterface {
            /**
             * Pass the calls on to the given store, each one event loop turn later.
             */
            public function __construct(
                /**
                 * The store that serves the calls.
                 */
                private readonly StoreInterface $inner,
            ) {}

            /**
             * Get the value from the inner store, one event loop turn later.
             */
            public function get(string $key): mixed
            {
                Async\later();

                return $this->inner->get($key);
            }

            /**
             * Compute the value with the inner store, one event loop turn later.
             */
            public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                Async\later();

                return $this->inner->compute($key, $computer, $ttl);
            }

            /**
             * Update the value with the inner store, one event loop turn later.
             */
            public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                Async\later();

                return $this->inner->update($key, $computer, $ttl);
            }

            /**
             * Delete the value from the inner store, one event loop turn later.
             */
            public function delete(string $key): void
            {
                Async\later();

                $this->inner->delete($key);
            }
        };
    }

    /**
     * A store whose every call returns to its caller one event loop turn after the given store has served
     * it, as the calls of a store that does I/O might: the store no longer holds the key when the caller
     * resumes.
     */
    private static function oneTurnAfterEachCallStore(StoreInterface $inner): StoreInterface
    {
        return new class($inner) implements StoreInterface {
            /**
             * Pass the calls on to the given store, and return from each one event loop turn later.
             */
            public function __construct(
                /**
                 * The store that serves the calls.
                 */
                private readonly StoreInterface $inner,
            ) {}

            /**
             * Get the value from the inner store, and return one event loop turn later.
             */
            public function get(string $key): mixed
            {
                try {
                    return $this->inner->get($key);
                } finally {
                    Async\later();
                }
            }

            /**
             * Compute the value with the inner store, and return one event loop turn later.
             */
            public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                try {
                    return $this->inner->compute($key, $computer, $ttl);
                } finally {
                    Async\later();
                }
            }

            /**
             * Update the value with the inner store, and return one event loop turn later.
             */
            public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                try {
                    return $this->inner->update($key, $computer, $ttl);
                } finally {
                    Async\later();
                }
            }

            /**
             * Delete the value from the inner store, and return one event loop turn later.
             */
            public function delete(string $key): void
            {
                try {
                    $this->inner->delete($key);
                } finally {
                    Async\later();
                }
            }
        };
    }

    /**
     * A store whose first call of the given kind throws, as the calls of a store that loses its connection
     * might: a `compute()` call throws once the given store has served it, so after the computer has run,
     * and an `update()` call throws before the given store serves it.
     *
     * @param 'compute'|'update' $throwingCall
     */
    private static function storeThrowingOnce(StoreInterface $inner, string $throwingCall): StoreInterface
    {
        return new class($inner, $throwingCall) implements StoreInterface {
            /**
             * Whether the first call of the throwing kind has thrown already.
             */
            private bool $thrown = false;

            /**
             * Pass the calls on to the given store, and throw once on the first call of the given kind.
             *
             * @param 'compute'|'update' $throwingCall
             */
            public function __construct(
                /**
                 * The store that serves the calls.
                 */
                private readonly StoreInterface $inner,

                /**
                 * The kind of call that throws once.
                 *
                 * @var 'compute'|'update'
                 */
                private readonly string $throwingCall,
            ) {}

            /**
             * Get the value from the inner store.
             */
            public function get(string $key): mixed
            {
                return $this->inner->get($key);
            }

            /**
             * Compute the value with the inner store, then throw if this is the first `compute()` call and
             * `compute()` calls throw.
             */
            public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                $value = $this->inner->compute($key, $computer, $ttl);
                $this->throwOnce('compute');

                return $value;
            }

            /**
             * Throw if this is the first `update()` call and `update()` calls throw, or update the value
             * with the inner store.
             */
            public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                $this->throwOnce('update');

                return $this->inner->update($key, $computer, $ttl);
            }

            /**
             * Delete the value from the inner store.
             */
            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            /**
             * Throw if calls of the given kind throw and none has thrown yet.
             *
             * @param 'compute'|'update' $call
             *
             * @throws RuntimeException If calls of the given kind throw and none has thrown yet.
             */
            private function throwOnce(string $call): void
            {
                if ($this->thrown || $call !== $this->throwingCall) {
                    return;
                }

                $this->thrown = true;

                throw new RuntimeException('The store is unavailable.');
            }
        };
    }

    /**
     * @param list<array{key: string, ttl: Duration|null}> $updateCalls
     */
    private static function createTrackingUpdateCacheStore(array &$updateCalls): StoreInterface
    {
        return new class($updateCalls) implements StoreInterface {
            /** @var list<array{key: string, ttl: Duration|null}> */
            private array $calls;

            /** @param list<array{key: string, ttl: Duration|null}> $calls */
            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function get(string $key): mixed
            {
                throw new UnavailableItemException($key);
            }

            public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                return $computer();
            }

            public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                $this->calls[] = ['key' => $key, 'ttl' => $ttl];
                return $computer(null);
            }

            public function delete(string $key): void {}
        };
    }

    /**
     * @param list<Duration|null> $computeTtls
     */
    private static function createComputeTrackingCacheStore(array &$computeTtls): StoreInterface
    {
        return new class($computeTtls) implements StoreInterface {
            /** @var list<Duration|null> */
            private array $ttls;

            /** @param list<Duration|null> $ttls */
            public function __construct(array &$ttls)
            {
                $this->ttls = &$ttls;
            }

            public function get(string $key): mixed
            {
                throw new UnavailableItemException($key);
            }

            public function compute(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                $this->ttls[] = $ttl;
                return $computer();
            }

            public function update(string $key, Closure $computer, null|Duration $ttl = null): mixed
            {
                return $computer(null);
            }

            public function delete(string $key): void {}
        };
    }

    /**
     * A resolver that counts its queries and answers each one once the gate completes.
     *
     * A query waits for the gate with its own cancellation token, then takes one event loop turn, so a
     * query that starts after the gate has completed still suspends, as a query to a server would.
     *
     * @param Async\Awaitable<null> $gate
     */
    private static function gatedResolver(Async\Awaitable $gate, int &$calls): ResolverInterface
    {
        return new class($gate, $calls) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            /**
             * The number of queries, a reference to the caller's counter.
             */
            private int $calls;

            /**
             * Answer the queries once the given gate completes, and count them in `$calls`.
             *
             * @param Async\Awaitable<null> $gate
             */
            public function __construct(
                /**
                 * The gate each query waits for.
                 */
                private readonly Async\Awaitable $gate,
                int &$calls,
            ) {
                $this->calls = &$calls;
            }

            /**
             * Count the query, wait for the gate with the query's token, take one event loop turn, and answer
             * with one A record.
             */
            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                $this->calls++;
                $this->gate->await($cancellation);
                Async\later();

                return new Response(
                    0,
                    ResponseCode::NoError,
                    [new ARecord($name, Duration::seconds(300), Address::v4('10.0.0.1'))],
                    [],
                    [],
                );
            }
        };
    }

    /**
     * A resolver that counts its queries and answers each one 10 ms later with one record for its question,
     * named after the queried name: an AAAA record for an AAAA query, and an A record for any other query,
     * whose address is 10.0.0.2 if the query has EDNS options and 10.0.0.1 if it has none.
     */
    private static function questionAnsweringResolver(int &$calls): ResolverInterface
    {
        return new class($calls) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            /**
             * The number of queries, a reference to the caller's counter.
             */
            private int $calls;

            /**
             * Answer the queries, and count them in `$calls`.
             */
            public function __construct(int &$calls)
            {
                $this->calls = &$calls;
            }

            /**
             * Count the query, wait 10 ms, and answer with one record for the question.
             */
            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                $this->calls++;
                Async\sleep(Duration::milliseconds(10));
                $ttl = Duration::seconds(300);
                $answer = RecordType::AAAA === $type
                    ? new AAAARecord($name, $ttl, Address::v6('2001:db8::1'))
                    : new ARecord($name, $ttl, Address::v4([] === $ednsOptions ? '10.0.0.1' : '10.0.0.2'));

                return new Response(0, ResponseCode::NoError, [$answer], [], []);
            }
        };
    }

    /**
     * The name, the type and the address of the first answer of the given response, such as
     * "example.com A 10.0.0.1".
     */
    private static function describeAnswer(Response $response): string
    {
        $answer = $response->answers[0] ?? null;
        if (!$answer instanceof ARecord && !$answer instanceof AAAARecord) {
            return 'no address record';
        }

        return $answer->name . ' ' . $answer->kind->name . ' ' . $answer->address->toString();
    }

    /**
     * @param Closure(): Response $handler
     */
    private static function countingResolver(Closure $handler): object
    {
        return new class($handler) implements ResolverInterface {
            /** @var Closure(): Response */
            private Closure $handler;

            public function __construct(Closure $handler)
            {
                $this->handler = $handler;
            }

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return ($this->handler)();
            }

            public function reverseQuery(
                Address $ip,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return ($this->handler)();
            }
        };
    }
}
