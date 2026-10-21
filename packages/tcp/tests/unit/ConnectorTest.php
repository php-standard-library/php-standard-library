<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\Network;
use Psl\TCP;

final class ConnectorTest extends TestCase
{
    public function testConnectorConnectsSuccessfully(): void
    {
        Async\concurrently([
            'server' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 8190);
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('ping', $data);
                $connection->writeAll('pong');
                $connection->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new TCP\Connector();
                $stream = $connector->connect('127.0.0.1', 8190);
                $stream->writeAll('ping');
                $response = $stream->readAll();
                self::assertSame('pong', $response);
                $stream->close();
            },
        ]);
    }

    public function testConnectorDefault(): void
    {
        $connector = TCP\Connector::default();
        static::assertInstanceOf(TCP\Connector::class, $connector);
    }

    public function testStaticConnectorIgnoresPassedHostPort(): void
    {
        Async\concurrently([
            'server' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 8191);
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('hello', $data);
                $connection->writeAll('world');
                $connection->close();
                $listener->close();
            },
            'client' => static function (): void {
                // StaticConnector always connects to 127.0.0.1:8191
                $connector = new TCP\StaticConnector('127.0.0.1', 8191);
                // Even though we pass a different host/port, it connects to the static one
                $stream = $connector->connect('10.0.0.1', 9999);
                $stream->writeAll('hello');
                $response = $stream->readAll();
                self::assertSame('world', $response);
                $stream->close();
            },
        ]);
    }

    public function testRetryConnectorDoesNotRetryNonRetryableFailures(): void
    {
        $attempts = 0;
        $rejected = new class('certificate rejected') extends Network\Exception\RuntimeException implements
            Network\Exception\NonRetryableExceptionInterface {};

        $failingConnector = new class($attempts, $rejected) implements TCP\ConnectorInterface {
            public function __construct(
                private int &$attempts,
                private readonly Network\Exception\RuntimeException $rejected,
            ) {}

            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                $this->attempts++;

                throw $this->rejected;
            }
        };

        $connector = new TCP\RetryConnector($failingConnector, maxAttempts: 3, backoff: Duration::milliseconds(1));

        try {
            $connector->connect('127.0.0.1', 1);
            static::fail('Expected the failure to propagate.');
        } catch (Network\Exception\RuntimeException $exception) {
            static::assertSame($rejected, $exception);
        }

        static::assertSame(1, $attempts);
    }

    public function testRetryConnectorSucceedsOnFirstAttempt(): void
    {
        Async\concurrently([
            'server' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 8192);
                $connection = $listener->accept();
                $data = $connection->read();
                self::assertSame('retry-test', $data);
                $connection->writeAll('ok');
                $connection->close();
                $listener->close();
            },
            'client' => static function (): void {
                $connector = new TCP\RetryConnector(new TCP\Connector(), maxAttempts: 3);
                $stream = $connector->connect('127.0.0.1', 8192);
                $stream->writeAll('retry-test');
                $response = $stream->readAll();
                self::assertSame('ok', $response);
                $stream->close();
            },
        ]);
    }

    public function testRetryConnectorRetriesAndSucceeds(): void
    {
        $attempts = 0;

        $failingConnector = new class($attempts) implements TCP\ConnectorInterface {
            public function __construct(
                private int &$attempts,
            ) {}

            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                $this->attempts++;
                if ($this->attempts < 3) {
                    throw new Network\Exception\RuntimeException('Connection failed');
                }

                return TCP\connect($host, $port, cancellation: $cancellation);
            }
        };

        Async\concurrently([
            'server' => static function (): void {
                $listener = TCP\listen('127.0.0.1', 8193);
                $connection = $listener->accept();
                $connection->writeAll('success');
                $connection->close();
                $listener->close();
            },
            'client' => static function () use ($failingConnector, &$attempts): void {
                $connector = new TCP\RetryConnector(
                    $failingConnector,
                    maxAttempts: 3,
                    backoff: Duration::milliseconds(10),
                );
                $stream = $connector->connect('127.0.0.1', 8193);
                $data = $stream->readAll();
                self::assertSame('success', $data);
                self::assertSame(3, $attempts);
                $stream->close();
            },
        ]);
    }

    public function testRetryConnectorThrowsAfterMaxAttempts(): void
    {
        $alwaysFails = new class() implements TCP\ConnectorInterface {
            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                throw new Network\Exception\RuntimeException('Always fails');
            }
        };

        $this->expectException(Network\Exception\RuntimeException::class);
        $this->expectExceptionMessageMatches('/after 2 attempts/');

        $connector = new TCP\RetryConnector($alwaysFails, maxAttempts: 2, backoff: Duration::milliseconds(10));
        $connector->connect('127.0.0.1', 80);
    }

    public function testRetryConnectorCancelledDuringBackoff(): void
    {
        $attempts = 0;

        $alwaysFails = new class($attempts) implements TCP\ConnectorInterface {
            public function __construct(
                private int &$attempts,
            ) {}

            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                $this->attempts++;

                throw new Network\Exception\RuntimeException('Connection failed');
            }
        };

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function () use ($alwaysFails, &$attempts): void {
            $connector = new TCP\RetryConnector($alwaysFails, maxAttempts: 10, backoff: Duration::seconds(5));

            $token = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

            $connector->connect('127.0.0.1', 9999, $token);
        })->await();
    }

    public function testRetryConnectorCancelledDuringBackoffStopsRetrying(): void
    {
        $attempts = 0;

        $alwaysFails = new class($attempts) implements TCP\ConnectorInterface {
            public function __construct(
                private int &$attempts,
            ) {}

            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                $this->attempts++;

                throw new Network\Exception\RuntimeException('Connection failed');
            }
        };

        try {
            Async\run(static function () use ($alwaysFails): void {
                $connector = new TCP\RetryConnector($alwaysFails, maxAttempts: 100, backoff: Duration::seconds(5));

                $token = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

                $connector->connect('127.0.0.1', 9999, $token);
            })->await();
        } catch (Async\Exception\CancelledException) {
            // @mago-expect lint:no-empty-catch-clause - expected
        }

        // Should have only attempted once or twice before the backoff was cancelled
        static::assertLessThan(5, $attempts);
    }

    public function testRetryConnectorAlreadyCancelledToken(): void
    {
        $alwaysFails = new class() implements TCP\ConnectorInterface {
            public function connect(
                string $host,
                int $port,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): TCP\StreamInterface {
                throw new Network\Exception\RuntimeException('Connection failed');
            }
        };

        $token = new Async\SignalCancellationToken();
        $token->cancel();

        $this->expectException(Async\Exception\CancelledException::class);

        Async\run(static function () use ($alwaysFails, $token): void {
            $connector = new TCP\RetryConnector($alwaysFails, maxAttempts: 10, backoff: Duration::seconds(5));

            $connector->connect('127.0.0.1', 9999, $token);
        })->await();
    }
}
