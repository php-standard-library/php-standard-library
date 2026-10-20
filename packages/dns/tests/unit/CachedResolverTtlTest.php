<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Cache\LocalStore;
use Psl\DateTime\Duration;
use Psl\DNS\CachedResolver;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\NSRecord;
use Psl\DNS\Record\OPTRecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\SOARecord;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\IP\Address;

final class CachedResolverTtlTest extends TestCase
{
    public function testCachedRecordsAgeWithoutChangingOriginals(): void
    {
        $answer = new ARecord('example.com', Duration::seconds(30), Address::v4('192.0.2.1'));
        $authority = new NSRecord('example.com', Duration::seconds(1), 'ns.example.com');
        $additional = new ARecord('ns.example.com', Duration::seconds(300), Address::v4('192.0.2.2'));
        $opt = new OPTRecord('', Duration::zero(), 1232, 0, 0, true, []);
        $original = new Response(
            42,
            ResponseCode::NoError,
            [$answer],
            [$authority],
            [$additional, $opt],
            true,
            true,
            true,
            true,
            true,
        );
        $inner = $this->createMock(ResolverInterface::class);
        $inner->expects(static::once())->method('query')->willReturn($original);
        $resolver = new CachedResolver($inner, new LocalStore());
        $resolver->query('example.com', RecordType::A);
        Async\sleep(Duration::milliseconds(1100));
        $cached = $resolver->query('example.com', RecordType::A);

        static::assertLessThanOrEqual(28, $cached->answers[0]->duration->getTotalSeconds());
        static::assertGreaterThan(0, $cached->answers[0]->duration->getTotalSeconds());
        static::assertSame(0.0, $cached->authority[0]->duration->getTotalSeconds());
        static::assertEquals($cached->answers[0]->duration, $cached->additional[0]->duration);
        static::assertNotSame($answer, $cached->answers[0]);
        static::assertEquals(
            new Response(
                42,
                ResponseCode::NoError,
                $cached->answers,
                $cached->authority,
                $cached->additional,
                true,
                true,
                true,
                true,
                true,
            ),
            $cached,
        );
        static::assertSame($opt, $cached->additional[1]);
        static::assertSame(30.0, $answer->duration->getTotalSeconds());
        static::assertSame(1.0, $authority->duration->getTotalSeconds());
        static::assertSame(300.0, $additional->duration->getTotalSeconds());
    }

    public function testNegativeTtlIsCappedByRemainingCacheLifetime(): void
    {
        $soa = new SOARecord(
            'example.com',
            Duration::seconds(300),
            'ns.example.com',
            'hostmaster.example.com',
            1,
            Duration::hours(1),
            Duration::minutes(5),
            Duration::hours(24),
            Duration::seconds(30),
        );
        $inner = $this->createMock(ResolverInterface::class);
        $inner
            ->expects(static::once())
            ->method('query')
            ->willReturn(new Response(0, ResponseCode::NonExistentDomain, [], [$soa], []));
        $resolver = new CachedResolver($inner, new LocalStore());
        $resolver->query('missing.example.com', RecordType::A);
        Async\sleep(Duration::milliseconds(1100));
        $cached = $resolver->query('missing.example.com', RecordType::A);

        static::assertLessThanOrEqual(28, $cached->authority[0]->duration->getTotalSeconds());
        static::assertGreaterThan(0, $cached->authority[0]->duration->getTotalSeconds());
        static::assertSame(300.0, $soa->duration->getTotalSeconds());
    }

    public function testOuterCacheCannotRestartTheOriginalLifetime(): void
    {
        $calls = 0;
        $inner = $this->createMock(ResolverInterface::class);
        $inner
            ->expects(static::exactly(2))
            ->method('query')
            ->willReturnCallback(static function () use (&$calls): Response {
                $calls++;
                return new Response(
                    0,
                    ResponseCode::NoError,
                    [new ARecord('example.com', Duration::seconds(2), Address::v4('192.0.2.1'))],
                    [],
                    [],
                );
            });
        $cache = new CachedResolver($inner, new LocalStore());
        $cache->query('example.com', RecordType::A);
        Async\sleep(Duration::milliseconds(1100));
        $outer = new CachedResolver($cache, new LocalStore());
        $outer->query('example.com', RecordType::A);
        Async\sleep(Duration::milliseconds(1100));
        $outer->query('example.com', RecordType::A);

        static::assertSame(2, $calls);
    }
}
