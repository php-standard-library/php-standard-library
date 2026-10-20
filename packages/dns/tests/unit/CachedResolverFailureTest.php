<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Cache\LocalStore;
use Psl\DateTime\Duration;
use Psl\DNS\CachedResolver;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Exception\NetworkException;
use Psl\DNS\Exception\RuntimeException;
use Psl\DNS\FallbackResolver;
use Psl\DNS\RacingResolver;
use Psl\DNS\Record\RecordType;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Throwable;

final class CachedResolverFailureTest extends TestCase
{
    /** @return iterable<string, array{ResponseCode}> */
    public static function failureCodes(): iterable
    {
        yield 'SERVFAIL' => [ResponseCode::ServerFailure];
        yield 'REFUSED' => [ResponseCode::ServerRefused];
    }

    #[DataProvider('failureCodes')]
    public function testReusesFailureUntilItsOwnTtlExpires(ResponseCode $code): void
    {
        $inner = $this->createMock(ResolverInterface::class);
        $inner
            ->expects(static::exactly(2))
            ->method('query')
            ->willReturn(new Response(0, $code, [], [], []));
        $resolver = new CachedResolver($inner, new LocalStore(), failureTtl: 1);
        static::assertSame($code, $resolver->query('example.com', RecordType::A)->code);
        static::assertSame($code, $resolver->query('example.com', RecordType::A)->code);
        Async\sleep(Duration::milliseconds(1100));
        static::assertSame($code, $resolver->query('example.com', RecordType::A)->code);
    }

    /** @return iterable<string, array{Throwable}> */
    public static function networkFailures(): iterable
    {
        yield 'network' => [NetworkException::forQueryFailed('UDP', 'server unreachable')];
        yield 'timeout' => [new Async\Exception\TimeoutException()];
        yield 'timeout cancellation' => [new Async\Exception\CancelledException(
            new Async\NullCancellationToken(),
            new Async\Exception\TimeoutException(),
        )];
    }

    #[DataProvider('networkFailures')]
    public function testReusesNetworkFailure(Throwable $failure): void
    {
        $inner = $this->createMock(ResolverInterface::class);
        $inner->expects(static::once())->method('query')->willThrowException($failure);
        $resolver = new CachedResolver($inner, new LocalStore());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $resolver->query('example.com', RecordType::A);
                static::fail('Expected the cached failure.');
            } catch (Throwable $exception) {
                static::assertSame($failure, $exception);
            }
        }
    }

    public function testRetriesTimeoutAfterExpiry(): void
    {
        $failure = new Async\Exception\TimeoutException();
        $inner = $this->createMock(ResolverInterface::class);
        $inner->expects(static::exactly(2))->method('query')->willThrowException($failure);
        $resolver = new CachedResolver($inner, new LocalStore(), failureTtl: 1);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt === 2) {
                Async\sleep(Duration::milliseconds(1100));
            }

            try {
                $resolver->query('example.com', RecordType::A);
                static::fail('Expected a timeout.');
            } catch (Async\Exception\TimeoutException $exception) {
                static::assertSame($failure, $exception);
            }
        }
    }

    public function testCallerCancellationIsNotCached(): void
    {
        $failure = new Async\Exception\CancelledException(new Async\NullCancellationToken());
        $inner = $this->createMock(ResolverInterface::class);
        $inner->expects(static::exactly(2))->method('query')->willThrowException($failure);
        $resolver = new CachedResolver($inner, new LocalStore());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $resolver->query('example.com', RecordType::A);
                static::fail('Expected cancellation.');
            } catch (Async\Exception\CancelledException $exception) {
                static::assertSame($failure, $exception);
            }
        }
    }

    public function testCallerDeadlineDoesNotPoisonSharedCache(): void
    {
        $inner = $this->createMock(ResolverInterface::class);
        $inner
            ->expects(static::exactly(2))
            ->method('query')
            ->willReturnCallback(static function (
                string $_name,
                RecordType $_type,
                Async\CancellationTokenInterface $cancellation,
            ): Response {
                Async\sleep(Duration::milliseconds(20), $cancellation);
                return new Response(0, ResponseCode::NoError, [], [], []);
            });
        $resolver = new CachedResolver($inner, new LocalStore());
        $deadline = new Async\TimeoutCancellationToken(Duration::milliseconds(1));
        try {
            $resolver->query('example.com', RecordType::A, $deadline);
            static::fail('Expected the caller deadline.');
        } catch (Async\Exception\CancelledException $exception) {
            static::assertSame($deadline, $exception->getToken());
        }

        static::assertSame(ResponseCode::NoError, $resolver->query('example.com', RecordType::A)->code);
    }

    public function testFailureDoesNotReplaceOtherNamesOrTypes(): void
    {
        $inner = $this->createMock(ResolverInterface::class);
        $inner
            ->expects(static::exactly(3))
            ->method('query')
            ->willReturn(new Response(0, ResponseCode::ServerFailure, [], [], []));
        $resolver = new CachedResolver($inner, new LocalStore());
        $resolver->query('example.com', RecordType::A);
        $resolver->query('example.com', RecordType::A);
        $resolver->query('other.example.com', RecordType::A);
        $resolver->query('example.com', RecordType::AAAA);
    }

    /** @return iterable<string, array{bool}> */
    public static function compositeResolvers(): iterable
    {
        yield 'fallback' => [false];
        yield 'racing' => [true];
    }

    #[DataProvider('compositeResolvers')]
    public function testCachesCompleteResolutionFailure(bool $racing): void
    {
        $inner = $this->createMock(ResolverInterface::class);
        $inner
            ->expects(static::once())
            ->method('query')
            ->willReturn(new Response(0, ResponseCode::ServerFailure, [], [], []));
        $composite = $racing ? new RacingResolver([$inner]) : new FallbackResolver([$inner]);
        $resolver = new CachedResolver($composite, new LocalStore());
        $first = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $resolver->query('example.com', RecordType::A);
                static::fail('Expected complete resolution failure.');
            } catch (RuntimeException $exception) {
                $first ??= $exception;
                static::assertSame($first, $exception);
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function invalidFailureTtls(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero' => [0];
        yield 'over five minutes' => [301];
    }

    #[DataProvider('invalidFailureTtls')]
    public function testRejectsFailureTtlOutsideRfcBounds(int $ttl): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CachedResolver($this->createStub(ResolverInterface::class), new LocalStore(), failureTtl: $ttl);
    }
}
