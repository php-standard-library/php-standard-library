<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\Configuration;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ConnectionException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;

use function count;
use function pack;
use function str_repeat;

use const Psl\H2\CONNECTION_PREFACE;

final class FrameSizeLimitTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function oversizedFrames(): iterable
    {
        yield 'SETTINGS' => [4, 0];
        yield 'PING' => [6, 0];
        yield 'GOAWAY' => [7, 0];
        yield 'unknown connection frame' => [255, 0];
        yield 'unknown stream frame' => [255, 1];
    }

    #[DataProvider('oversizedFrames')]
    public function testAllFrameTypesHaveSizeLimits(int $type, int $streamId): void
    {
        try {
            new StateMachine(true)->receive(new RawFrame($type, 0, $streamId, str_repeat('x', 16_385)));
            static::fail('Oversized frame accepted');
        } catch (ProtocolException $e) {
            static::assertSame(ErrorCode::FrameSizeError, $e->errorCode);
        }
    }

    public function testRejectsOversizedHeaderWithoutReadingPayload(): void
    {
        $handle = new ChunkedHandle(pack('NCN', (16_385 << 8) | 255, 0, 0), 9);
        $connection = new ClientConnection($handle);

        try {
            $connection->readEvent();
            static::fail('Oversized frame accepted');
        } catch (ProtocolException $e) {
            static::assertSame(ErrorCode::FrameSizeError, $e->errorCode);
        }

        static::assertFalse($handle->reachedEndOfDataSource());
        static::assertFalse($connection->isConnected());
        [$raw] = Frame\decode($handle->written);
        static::assertSame(ErrorCode::FrameSizeError->value, GoAwayFrame::fromRaw($raw)->errorCode);
    }

    public function testRejectsOversizedFrameBeforeReadingItsPayload(): void
    {
        $wire = pack('NCN', (16_385 << 8) | 255, 0, 0) . str_repeat('x', 16_385);
        $handle = new ChunkedHandle($wire, 20_000);
        $connection = new ClientConnection($handle);

        try {
            $connection->readEvent();
            static::fail('Oversized frame accepted');
        } catch (ProtocolException $exception) {
            static::assertSame(ErrorCode::FrameSizeError, $exception->errorCode);
        }

        static::assertLessThanOrEqual(4096, $handle->readPos);
    }

    public function testPrefaceDoesNotReadFollowingFramePayload(): void
    {
        $wire = CONNECTION_PREFACE . pack('NCN', (16_385 << 8) | 255, 0, 0) . str_repeat('x', 16_385);
        $handle = new ChunkedHandle($wire, 20_000);
        $connection = new ServerConnection($handle);
        $connection->readClientPreface();

        static::assertSame(24, $handle->readPos);

        try {
            $connection->readEvent();
            static::fail('Oversized frame accepted');
        } catch (ProtocolException $exception) {
            static::assertSame(ErrorCode::FrameSizeError, $exception->errorCode);
        }

        static::assertLessThanOrEqual(24 + 4096, $handle->readPos);
    }

    public function testSmallFramesShareTransportReads(): void
    {
        $wire = str_repeat(Frame\encode(new RawFrame(255, 0, 0, 'data')), 100);
        $handle = new ChunkedHandle($wire, 4096);
        $connection = new ClientConnection($handle);
        for ($i = 0; $i < 100; $i++) {
            static::assertSame([], $connection->readEvent());
        }

        static::assertLessThan(100, count($handle->readLimits));
    }

    public function testEndOfTransportDoesNotSendGoAway(): void
    {
        $handle = new ChunkedHandle('');
        $connection = new ClientConnection($handle);
        try {
            $connection->readEvent();
            static::fail('Expected a closed connection.');
        } catch (ConnectionException) {
            static::assertSame('', $handle->written);
        }
    }

    public function testAllowsConfiguredFrameSize(): void
    {
        $wire = Frame\encode(new RawFrame(255, 0, 0, str_repeat('x', 20_000)));
        $connection = new ClientConnection(
            new ChunkedHandle($wire, 9),
            new Configuration(settings: [
                Setting::MaxFrameSize->value => 20_000,
            ]),
        );
        static::assertSame([], $connection->readEvent());
    }
}
