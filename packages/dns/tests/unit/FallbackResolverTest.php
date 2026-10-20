<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Exception\NetworkException;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Exception\RuntimeException as DnsRuntimeException;
use Psl\DNS\FallbackResolver;
use Psl\DNS\Record\RecordType;
use Psl\DNS\ResolverConvenienceMethodsTrait;
use Psl\DNS\ResolverInterface;
use Psl\DNS\Response;
use Psl\DNS\ResponseCode;
use Psl\IP\Address;
use RuntimeException;
use Throwable;

final class FallbackResolverTest extends TestCase
{
    public function testReturnsResponseFromFirstResolverOnSuccess(): void
    {
        $response = new Response(1, ResponseCode::NoError, [], [], []);
        $first = $this->createMockResolver($response);
        $second = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'should not be called',
            new RuntimeException('test'),
        ));

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($response, $result);
    }

    public function testFallsBackToSecondResolverOnRuntimeException(): void
    {
        $response = new Response(2, ResponseCode::NoError, [], [], []);
        $first = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'connection failed',
            new RuntimeException('test'),
        ));
        $second = $this->createMockResolver($response);

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($response, $result);
    }

    public function testFallsBackToSecondResolverOnNetworkTimeoutException(): void
    {
        $response = new Response(3, ResponseCode::NoError, [], [], []);
        $first = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'timeout',
            new RuntimeException('test'),
        ));
        $second = $this->createMockResolver($response);

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($response, $result);
    }

    public function testFallsBackToSecondResolverOnMalformedResponseException(): void
    {
        $response = new Response(4, ResponseCode::NoError, [], [], []);
        $first = $this->createThrowingResolver(ProtocolException::forQueryPacket());
        $second = $this->createMockResolver($response);

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($response, $result);
    }

    public function testFallsBackOnServerFailureResponseCode(): void
    {
        $failResponse = new Response(5, ResponseCode::ServerFailure, [], [], []);
        $successResponse = new Response(6, ResponseCode::NoError, [], [], []);
        $first = $this->createMockResolver($failResponse);
        $second = $this->createMockResolver($successResponse);

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($successResponse, $result);
    }

    public function testFallsBackOnServerRefusedResponseCode(): void
    {
        $refusedResponse = new Response(7, ResponseCode::ServerRefused, [], [], []);
        $successResponse = new Response(8, ResponseCode::NoError, [], [], []);
        $first = $this->createMockResolver($refusedResponse);
        $second = $this->createMockResolver($successResponse);

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($successResponse, $result);
    }

    public function testReturnsNonExistentDomainWithoutFallback(): void
    {
        $nxResponse = new Response(9, ResponseCode::NonExistentDomain, [], [], []);
        $first = $this->createMockResolver($nxResponse);
        $second = $this->createMock(ResolverInterface::class);
        $second->expects(static::never())->method('query');

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('internal.database', RecordType::A);

        static::assertSame($nxResponse, $result);
    }

    public function testDoesNotRetryInvalidInput(): void
    {
        $error = InvalidArgumentException::forLabelContainsNull();
        $second = $this->createMock(ResolverInterface::class);
        $second->expects(static::never())->method('query');
        $resolver = new FallbackResolver([$this->createThrowingResolver($error), $second]);

        $this->expectExceptionObject($error);
        $resolver->query("bad\0.example", RecordType::A);
    }

    public function testReturnsFormatErrorWithoutFallback(): void
    {
        $response = new Response(10, ResponseCode::FormatError, [], [], []);
        $second = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'should not be called',
            new RuntimeException('test'),
        ));

        $resolver = new FallbackResolver([$this->createMockResolver($response), $second]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame(ResponseCode::FormatError, $result->code);
    }

    public function testThrowsWhenAllResolversFail(): void
    {
        $first = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'first failed',
            new RuntimeException('test'),
        ));
        $second = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'timeout',
            new RuntimeException('test'),
        ));

        $resolver = new FallbackResolver([$first, $second]);

        $this->expectException(DnsRuntimeException::class);
        $this->expectExceptionMessage('All fallback resolvers failed.');

        $resolver->query('example.com', RecordType::A);
    }

    public function testThrowsWithLastErrorAsPrevious(): void
    {
        $first = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'first',
            new RuntimeException('test'),
        ));
        $networkError = NetworkException::forQueryFailed('UDP', 'timeout', new RuntimeException('test'));
        $second = $this->createThrowingResolver($networkError);

        $resolver = new FallbackResolver([$first, $second]);

        try {
            $resolver->query('example.com', RecordType::A);
            static::fail('Expected DnsRuntimeException');
        } catch (DnsRuntimeException $e) {
            $previous = $e->getPrevious();
            static::assertInstanceOf(NetworkException::class, $previous);
            static::assertSame($networkError->getMessage(), $previous->getMessage());
        }
    }

    public function testThrowsWithServerErrorAsPreviousWhenAllServersReturnErrors(): void
    {
        $first = $this->createMockResolver(new Response(11, ResponseCode::ServerFailure, [], [], []));
        $second = $this->createMockResolver(new Response(12, ResponseCode::ServerRefused, [], [], []));

        $resolver = new FallbackResolver([$first, $second]);

        try {
            $resolver->query('example.com', RecordType::A);
            static::fail('Expected DnsRuntimeException');
        } catch (DnsRuntimeException $e) {
            static::assertSame('All fallback resolvers failed.', $e->getMessage());
            $previous = $e->getPrevious();
            static::assertInstanceOf(DnsRuntimeException::class, $previous);
            static::assertSame('DNS server returned ServerRefused.', $previous->getMessage());
        }
    }

    public function testReturnsNxdomainWhenAllResolversReturnNxdomain(): void
    {
        $first = $this->createMockResolver(new Response(0, ResponseCode::NonExistentDomain, [], [], []));
        $second = $this->createMockResolver(new Response(0, ResponseCode::NonExistentDomain, [], [], []));

        $resolver = new FallbackResolver([$first, $second]);
        $result = $resolver->query('nonexistent.example', RecordType::A);

        static::assertSame(ResponseCode::NonExistentDomain, $result->code);
    }

    public function testFallsBackThroughMultipleFailures(): void
    {
        $response = new Response(13, ResponseCode::NoError, [], [], []);
        $first = $this->createThrowingResolver(NetworkException::forQueryFailed(
            'UDP',
            'first',
            new RuntimeException('test'),
        ));
        $second = $this->createMockResolver(new Response(14, ResponseCode::ServerFailure, [], [], []));
        $third = $this->createMockResolver($response);

        $resolver = new FallbackResolver([$first, $second, $third]);
        $result = $resolver->query('example.com', RecordType::A);

        static::assertSame($response, $result);
    }

    public function testReverseQueryDelegatesToQuery(): void
    {
        $response = new Response(15, ResponseCode::NoError, [], [], []);
        $mock = $this->createMockResolver($response);

        $resolver = new FallbackResolver([$mock]);
        $result = $resolver->reverseQuery(Address::v4('192.168.1.1'));

        static::assertSame($response, $result);
    }

    private function createMockResolver(Response $response): ResolverInterface
    {
        return new class($response) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly Response $response,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                return $this->response;
            }
        };
    }

    private function createThrowingResolver(Throwable $exception): ResolverInterface
    {
        return new class($exception) implements ResolverInterface {
            use ResolverConvenienceMethodsTrait;

            public function __construct(
                private readonly Throwable $exception,
            ) {}

            public function query(
                string $name,
                RecordType $type,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
                array $ednsOptions = [],
            ): Response {
                throw $this->exception;
            }
        };
    }
}
