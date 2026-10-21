<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit;

use Override;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\HTTP\Client\ClientInterface;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\RetryClient;
use Psl\HTTP\Client\SendConfiguration;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Response;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\Network\Exception\NonRetryableExceptionInterface;
use Psl\Network\Exception\RuntimeException;
use Psl\URL;
use ReflectionMethod;

use function abs;

use const PHP_INT_MAX;
use const PHP_OS_FAMILY;

final class RetryClientTest extends TestCase
{
    /** @param non-empty-uppercase-string $method */
    private static function request(string $method = 'GET'): Request
    {
        return new Request(method: $method, url: URL\parse('http://example.com/'));
    }

    private static function failThenSucceedClient(int $failures): ClientInterface
    {
        return new class($failures) implements ClientInterface {
            public int $attempts = 0;

            public function __construct(
                private int $failures,
            ) {}

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;
                if ($this->attempts <= $this->failures) {
                    throw new RuntimeException('connection refused');
                }

                return new Transaction(
                    [],
                    null,
                    new Response(status: 200, headers: FieldMap::default(), body: new IO\MemoryHandle('ok')),
                );
            }
        };
    }

    private static function alwaysFailClient(): ClientInterface
    {
        return new class() implements ClientInterface {
            public int $attempts = 0;

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;
                throw new RuntimeException('connection refused');
            }
        };
    }

    public function testNoRetryOnSuccess(): void
    {
        $inner = self::failThenSucceedClient(0);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $transaction = $client->send(self::request());

        static::assertSame(200, $transaction->response->status);
        static::assertSame(1, $inner->attempts);
    }

    public function testRetriesOnNetworkError(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $transaction = $client->send(self::request());

        static::assertSame(200, $transaction->response->status);
        static::assertSame(3, $inner->attempts);
    }

    public function testExhaustsAttemptsThenThrows(): void
    {
        $inner = self::alwaysFailClient();
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $this->expectException(RuntimeException::class);

        try {
            $client->send(self::request());
        } finally {
            static::assertSame(3, $inner->attempts);
        }
    }

    public function testSingleAttemptNoRetry(): void
    {
        $inner = self::alwaysFailClient();
        $client = new RetryClient($inner, maxAttempts: 1, backoff: Duration::milliseconds(1));

        $this->expectException(RuntimeException::class);

        try {
            $client->send(self::request());
        } finally {
            static::assertSame(1, $inner->attempts);
        }
    }

    public function testDoesNotRetryPost(): void
    {
        $inner = self::alwaysFailClient();
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $this->expectException(RuntimeException::class);

        try {
            $client->send(self::request('POST'));
        } finally {
            static::assertSame(1, $inner->attempts);
        }
    }

    public function testDoesNotRetryPatch(): void
    {
        $inner = self::alwaysFailClient();
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $this->expectException(RuntimeException::class);

        try {
            $client->send(self::request('PATCH'));
        } finally {
            static::assertSame(1, $inner->attempts);
        }
    }

    public function testRetriesGet(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('GET'));

        static::assertSame(200, $tx->response->status);
    }

    public function testRetriesPut(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('PUT'));

        static::assertSame(200, $tx->response->status);
    }

    public function testRetriesDelete(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('DELETE'));

        static::assertSame(200, $tx->response->status);
    }

    public function testRetriesHead(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('HEAD'));

        static::assertSame(200, $tx->response->status);
    }

    public function testRetriesOptions(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('OPTIONS'));

        static::assertSame(200, $tx->response->status);
    }

    public function testRetriesTrace(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('TRACE'));

        static::assertSame(200, $tx->response->status);
    }

    public function testDoesNotRetryNonRetryableNetworkFailures(): void
    {
        $inner = new class() implements ClientInterface {
            public int $attempts = 0;

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;

                throw new class('certificate rejected') extends RuntimeException implements
                    NonRetryableExceptionInterface {};
            }
        };

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $this->expectException(NonRetryableExceptionInterface::class);

        try {
            $client->send(self::request());
        } finally {
            static::assertSame(1, $inner->attempts);
        }
    }

    public function testDoesNotRetryOnProtocolException(): void
    {
        $inner = new class() implements ClientInterface {
            public int $attempts = 0;

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;
                throw ProtocolException::forMalformedResponse('bad');
            }
        };

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $this->expectException(ProtocolException::class);

        try {
            $client->send(self::request());
        } finally {
            static::assertSame(1, $inner->attempts);
        }
    }

    public function testExponentialBackoffTiming(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(50), backoffMultiplier: 2);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(120, $elapsed);
    }

    public function testMultiplierOneGivesConstantDelay(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(50), backoffMultiplier: 1);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(80, $elapsed);
        static::assertLessThan(500, $elapsed);
    }

    public function testDefaultBackoffIsApplied(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 3);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(80, $elapsed);
    }

    public function testMinimalBackoff(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1), backoffMultiplier: 1);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertLessThan(50, $elapsed);
    }

    public function testSeekableBodyIsRewoundOnRetry(): void
    {
        $bodies = [];
        $inner = new class($bodies) implements ClientInterface {
            public int $attempts = 0;

            /** @param list<string> $bodies */
            public function __construct(
                private array &$bodies,
            ) {}

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;
                $this->bodies[] = $request->body?->readAll() ?? '';

                if ($this->attempts <= 1) {
                    throw new RuntimeException('connection refused');
                }

                return new Transaction([], null, new Response(status: 200, headers: FieldMap::default()));
            }
        };

        $body = new IO\MemoryHandle('hello world');
        $request = new Request(method: 'PUT', url: URL\parse('http://example.com/'), body: $body);

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));
        $tx = $client->send($request);

        static::assertSame(200, $tx->response->status);
        static::assertSame(2, $inner->attempts);
        static::assertSame('hello world', $bodies[0]);
        static::assertSame('hello world', $bodies[1]);
    }

    public function testNonSeekableBodyFailsFast(): void
    {
        $inner = self::alwaysFailClient();
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $body = new class() implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            public function tryRead(null|int $maxBytes = null): string
            {
                return '';
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return '';
            }

            public function reachedEndOfDataSource(): bool
            {
                return true;
            }
        };

        $request = new Request(method: 'PUT', url: URL\parse('http://example.com/'), body: $body);

        $this->expectException(RuntimeException::class);

        try {
            $client->send($request);
        } finally {
            static::assertSame(1, $inner->attempts);
        }
    }

    public function testNoBodyRetriesNormally(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request('GET'));

        static::assertSame(200, $tx->response->status);
        static::assertSame(3, $inner->attempts);
    }

    public function testDefaultMaxAttemptsIsExactly3(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, backoff: Duration::milliseconds(1));

        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
        static::assertSame(3, $inner->attempts);
    }

    public function testDefaultMaxAttemptsExceededAt4Failures(): void
    {
        $inner = self::alwaysFailClient();
        $client = new RetryClient($inner, backoff: Duration::milliseconds(1));

        $this->expectException(RuntimeException::class);

        try {
            $client->send(self::request());
        } finally {
            static::assertSame(3, $inner->attempts);
        }
    }

    public function testDefaultBackoffMultiplierIs2(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(50));

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(120, $elapsed);
    }

    public function testDefaultBackoffIs100ms(): void
    {
        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 2, backoffMultiplier: 1);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(80, $elapsed);
    }

    public function testRetriesOnIoRuntimeException(): void
    {
        $inner = new class() implements ClientInterface {
            public int $attempts = 0;

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;
                if ($this->attempts <= 1) {
                    throw new IO\Exception\RuntimeException('broken pipe');
                }

                return new Transaction(
                    [],
                    null,
                    new Response(status: 200, headers: FieldMap::default(), body: new IO\MemoryHandle('ok')),
                );
            }
        };

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
        static::assertSame(2, $inner->attempts);
    }

    public function testBackoffFormulaUsesAttemptMinusOne(): void
    {
        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(100), backoffMultiplier: 2);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(250, $elapsed);
        static::assertLessThan(550, $elapsed);
    }

    public function testDefaultBackoffMultiplierIs2NotHigher(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            static::markTestSkipped('Timing-sensitive test, only reliable on Linux CI.');
        }

        $inner = self::failThenSucceedClient(2);
        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(50));

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(120, $elapsed);
        static::assertLessThan(350, $elapsed);
    }

    public function testDefaultBackoffMultiplierIsExactly2(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            static::markTestSkipped('Timing-sensitive test, only reliable on Linux CI.');
        }

        $inner = self::failThenSucceedClient(2);
        $explicitMultiplier2 = new RetryClient(
            $inner,
            maxAttempts: 3,
            backoff: Duration::milliseconds(50),
            backoffMultiplier: 2,
        );

        $start1 = Timestamp::monotonic();
        $explicitMultiplier2->send(self::request());
        $elapsed1 = Timestamp::monotonic()->since($start1)->getTotalMilliseconds();

        $inner2 = self::failThenSucceedClient(2);
        $defaultMultiplier = new RetryClient($inner2, maxAttempts: 3, backoff: Duration::milliseconds(50));

        $start2 = Timestamp::monotonic();
        $defaultMultiplier->send(self::request());
        $elapsed2 = Timestamp::monotonic()->since($start2)->getTotalMilliseconds();

        static::assertLessThan(50, abs($elapsed1 - $elapsed2));
    }

    public function testDefaultBackoffMultiplierMatchesExplicit2(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            static::markTestSkipped('Timing-sensitive test, only reliable on Linux CI.');
        }

        $inner = self::failThenSucceedClient(3);
        $client = new RetryClient($inner, maxAttempts: 4, backoff: Duration::milliseconds(30));

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(180, $elapsed);
        static::assertLessThan(450, $elapsed);
    }

    public function testDefaultBackoffIsExactly100ms(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            static::markTestSkipped('Timing-sensitive test, only reliable on Linux CI.');
        }

        $inner = self::failThenSucceedClient(1);
        $client = new RetryClient($inner, maxAttempts: 2, backoffMultiplier: 1);

        $start = Timestamp::monotonic();
        $client->send(self::request());
        $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(90, $elapsed);
        static::assertLessThan(150, $elapsed);
    }

    public function testDefaultBackoffIsNot99msOr101ms(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            static::markTestSkipped('Timing-sensitive test, only reliable on Linux CI.');
        }

        $inner1 = self::failThenSucceedClient(1);
        $explicit99 = new RetryClient(
            $inner1,
            maxAttempts: 2,
            backoff: Duration::milliseconds(99),
            backoffMultiplier: 1,
        );

        $start1 = Timestamp::monotonic();
        $explicit99->send(self::request());
        $elapsed99 = Timestamp::monotonic()->since($start1)->getTotalMilliseconds();

        $inner2 = self::failThenSucceedClient(1);
        $explicit100 = new RetryClient(
            $inner2,
            maxAttempts: 2,
            backoff: Duration::milliseconds(100),
            backoffMultiplier: 1,
        );

        $start2 = Timestamp::monotonic();
        $explicit100->send(self::request());
        $elapsed100 = Timestamp::monotonic()->since($start2)->getTotalMilliseconds();

        $inner3 = self::failThenSucceedClient(1);
        $defaultBackoff = new RetryClient($inner3, maxAttempts: 2, backoffMultiplier: 1);

        $start3 = Timestamp::monotonic();
        $defaultBackoff->send(self::request());
        $elapsedDefault = Timestamp::monotonic()->since($start3)->getTotalMilliseconds();

        static::assertGreaterThanOrEqual(90, $elapsedDefault);
        static::assertLessThan(150, $elapsedDefault);

        static::assertGreaterThanOrEqual(90, $elapsed100);
    }

    public function testSeekableBodyIsRewoundToOriginalOffsetZero(): void
    {
        $seekOffsets = [];
        $inner = new class($seekOffsets) implements ClientInterface {
            public int $attempts = 0;

            public function __construct(
                private array &$seekOffsets,
            ) {}

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;

                if ($request->body instanceof IO\SeekHandleInterface) {
                    $this->seekOffsets[] = $request->body->tell();
                }

                $request->body?->readAll();

                if ($this->attempts <= 1) {
                    throw new RuntimeException('connection refused');
                }

                return new Transaction([], null, new Response(status: 200, headers: FieldMap::default()));
            }
        };

        $body = new IO\MemoryHandle('test body');
        $request = new Request(method: 'PUT', url: URL\parse('http://example.com/'), body: $body);

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));
        $tx = $client->send($request);

        static::assertSame(200, $tx->response->status);
        static::assertSame(2, $inner->attempts);
        static::assertSame(0, $seekOffsets[0]);
        static::assertSame(0, $seekOffsets[1]);
    }

    public function testSeekableBodyRewoundToInitialTellPosition(): void
    {
        $seekOffsets = [];
        $inner = new class($seekOffsets) implements ClientInterface {
            public int $attempts = 0;

            public function __construct(
                private array &$seekOffsets,
            ) {}

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;

                if ($request->body instanceof IO\SeekHandleInterface) {
                    $this->seekOffsets[] = $request->body->tell();
                }

                $request->body?->readAll();

                if ($this->attempts <= 1) {
                    throw new RuntimeException('connection refused');
                }

                return new Transaction([], null, new Response(status: 200, headers: FieldMap::default()));
            }
        };

        $body = new IO\MemoryHandle('hello world');
        $body->seek(5);
        $request = new Request(method: 'GET', url: URL\parse('http://example.com/'), body: $body);

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));
        $tx = $client->send($request);

        static::assertSame(200, $tx->response->status);
        static::assertSame(2, $inner->attempts);
        static::assertSame(5, $seekOffsets[0]);
        static::assertSame(5, $seekOffsets[1]);
    }

    public function testSeekableBodyOffsetIsZeroForNewHandle(): void
    {
        $seekValues = [];
        $inner = new class($seekValues) implements ClientInterface {
            public int $attempts = 0;

            public function __construct(
                private array &$seekValues,
            ) {}

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->attempts++;

                if ($request->body instanceof IO\SeekHandleInterface) {
                    $this->seekValues[] = $request->body->tell();
                    $request->body->readAll();
                }

                if ($this->attempts <= 2) {
                    throw new RuntimeException('connection refused');
                }

                return new Transaction([], null, new Response(status: 200, headers: FieldMap::default()));
            }
        };

        $body = new IO\MemoryHandle('data');
        $request = new Request(method: 'DELETE', url: URL\parse('http://example.com/resource'), body: $body);

        $client = new RetryClient($inner, maxAttempts: 3, backoff: Duration::milliseconds(1));
        $tx = $client->send($request);

        static::assertSame(200, $tx->response->status);
        static::assertSame(3, $inner->attempts);
        static::assertSame(0, $seekValues[0]);
        static::assertSame(0, $seekValues[1]);
        static::assertSame(0, $seekValues[2]);
    }

    public function testBackoffSaturatesInsteadOfOverflowing(): void
    {
        $method = new ReflectionMethod(RetryClient::class, 'saturatingMilliseconds');

        // Normal values pass through unchanged.
        static::assertSame(0, $method->invoke(null, 0.0));
        static::assertSame(100, $method->invoke(null, 100.0));
        static::assertSame(100, $method->invoke(null, 100.9));

        // Zero, negative and NAN collapse to no delay.
        static::assertSame(0, $method->invoke(null, -1.0));
        static::assertSame(0, $method->invoke(null, -INF));
        static::assertSame(0, $method->invoke(null, NAN));

        // INF and above-range values saturate at PHP_INT_MAX milliseconds.
        static::assertSame(PHP_INT_MAX, $method->invoke(null, INF));
        static::assertSame(PHP_INT_MAX, $method->invoke(null, 1.0e308));
        static::assertSame(PHP_INT_MAX, $method->invoke(null, (float) PHP_INT_MAX));
    }

    public function testLargeMultiplierDoesNotWarnOrWrap(): void
    {
        // The exponential formula overflows to a float here; it must not warn or
        // wrap to a negative delay. A cancelled token avoids the saturated sleep.
        $inner = self::alwaysFailClient();
        $client = new RetryClient(
            $inner,
            maxAttempts: 3,
            backoff: Duration::milliseconds(100),
            backoffMultiplier: PHP_INT_MAX,
        );
        $token = new \Psl\Async\SignalCancellationToken();
        $token->cancel();

        $this->expectException(\Psl\Async\Exception\CancelledException::class);
        $client->send(self::request(), new SendConfiguration(), $token);
    }
}
