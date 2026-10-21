<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\H2\ClientConnection;
use Psl\H2\Configuration;
use Psl\H2\ConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Event\PingReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PingFrame;
use Psl\H2\RateLimiter;
use Psl\H2\ServerConnection;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;

/**
 * A rate limiter given in a {@see Configuration} only supplies limits: every connection built from that
 * configuration counts its own peer's frames, starting from zero.
 */
final class ConnectionRateLimiterTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function roles(): iterable
    {
        yield 'server' => [false];
        yield 'client' => [true];
    }

    #[DataProvider('roles')]
    public function testOneConnectionExceedingAFrameLimitDoesNotAffectAnother(bool $client): void
    {
        $configuration = new Configuration(rateLimiter: new RateLimiter([
            FrameType::Ping->value => [2, Duration::seconds(10)],
        ]));

        $first = self::connect($client, str_repeat(self::ping(), 3), $configuration);
        self::assertReadsPings($first, 2);
        self::assertRateLimited($first, ErrorCode::ProtocolError);

        $second = self::connect($client, str_repeat(self::ping(), 2), $configuration);
        self::assertReadsPings($second, 2);
        static::assertTrue($second->isConnected());
    }

    #[DataProvider('roles')]
    public function testAConnectionStartsWithNoRecordedFramesWhenTheConfiguredLimiterIsUsed(bool $client): void
    {
        $limiter = new RateLimiter([FrameType::Ping->value => [2, Duration::seconds(10)]]);
        $limiter->record(FrameType::Ping->value);
        $limiter->record(FrameType::Ping->value);

        $connection = self::connect($client, str_repeat(self::ping(), 3), new Configuration(rateLimiter: $limiter));

        self::assertReadsPings($connection, 2);
        self::assertRateLimited($connection, ErrorCode::ProtocolError);

        // The connection counted on its own state, so the configured limiter is still at its limit.
        $this->expectException(ProtocolException::class);
        $limiter->record(FrameType::Ping->value);
    }

    public function testOneConnectionExceedingTheErrorResetLimitDoesNotAffectAnother(): void
    {
        $configuration = new Configuration(rateLimiter: new RateLimiter([
            RateLimiter::STREAM_ERROR_RESET => [2, Duration::seconds(10)],
        ]));

        $first = new ServerConnection(new ChunkedHandle(self::malformedHeaders(3)), $configuration);
        self::assertResetsStreams($first, 2);
        self::assertRateLimited($first, ErrorCode::EnhanceYourCalm);

        $second = new ServerConnection(new ChunkedHandle(self::malformedHeaders(2)), $configuration);
        self::assertResetsStreams($second, 2);
        static::assertTrue($second->isConnected());
    }

    public function testAConnectionStartsWithNoRecordedErrorResetsWhenTheConfiguredLimiterIsUsed(): void
    {
        $limiter = new RateLimiter([RateLimiter::STREAM_ERROR_RESET => [2, Duration::seconds(10)]]);
        $limiter->record(RateLimiter::STREAM_ERROR_RESET);
        $limiter->record(RateLimiter::STREAM_ERROR_RESET);

        $connection = new ServerConnection(
            new ChunkedHandle(self::malformedHeaders(3)),
            new Configuration(rateLimiter: $limiter),
        );

        self::assertResetsStreams($connection, 2);
        self::assertRateLimited($connection, ErrorCode::EnhanceYourCalm);
    }

    private static function connect(bool $client, string $input, Configuration $configuration): ConnectionInterface
    {
        $handle = new ChunkedHandle($input);

        return $client ? new ClientConnection($handle, $configuration) : new ServerConnection($handle, $configuration);
    }

    private static function ping(): string
    {
        return Frame\encode(new PingFrame('12345678', false)->toRaw());
    }

    /**
     * Header blocks without the required pseudo-headers, each on a new stream: the server resets every one of
     * those streams because of the peer's error.
     */
    private static function malformedHeaders(int $count): string
    {
        $encoder = new Encoder();
        $input = '';
        for ($i = 0; $i < $count; $i++) {
            $block = $encoder->encode([new Header(':method', 'GET')]);
            $input .= Frame\encode(new HeadersFrame(1 + (2 * $i), $block, false, true)->toRaw());
        }

        return $input;
    }

    private static function assertReadsPings(ConnectionInterface $connection, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $events = $connection->readEvent();

            static::assertCount(1, $events);
            static::assertInstanceOf(PingReceived::class, $events[0]);
        }
    }

    private static function assertResetsStreams(ServerConnection $connection, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            try {
                $connection->readEvent();

                static::fail('Expected the malformed header block to reset its stream.');
            } catch (StreamException $exception) {
                static::assertSame(ErrorCode::ProtocolError, $exception->errorCode);
            }
        }
    }

    private static function assertRateLimited(ConnectionInterface $connection, ErrorCode $errorCode): void
    {
        try {
            $connection->readEvent();

            static::fail('Expected the rate limit to end the connection.');
        } catch (ProtocolException $exception) {
            static::assertStringContainsStringIgnoringCase('rate limit exceeded', $exception->getMessage());
            static::assertSame($errorCode, $exception->errorCode);
            static::assertFalse($connection->isConnected());
        }
    }
}
