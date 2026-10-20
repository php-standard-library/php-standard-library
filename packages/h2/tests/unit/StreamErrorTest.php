<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\ServerConnection;
use Psl\H2\StreamState;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class StreamErrorTest extends TestCase
{
    #[DataProvider('malformedFields')]
    public function testMalformedHeadersResetOnlyTheirStream(Header $invalid): void
    {
        $encoder = new Encoder();
        $headers = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        $handle = new ChunkedHandle(
            Frame\encode(new HeadersFrame(1, $encoder->encode([...$headers, $invalid]), false, true)->toRaw())
                . Frame\encode(new HeadersFrame(3, $encoder->encode($headers), true, true)->toRaw()),
        );
        $connection = new ServerConnection($handle);

        try {
            $connection->readEvent();
            static::fail('Expected a stream error.');
        } catch (StreamException $error) {
            static::assertSame(1, $error->streamId);
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            static::assertTrue($connection->isConnected());
            static::assertSame(StreamState::Closed, $connection->getStreamState(1));
            [$raw] = Frame\decode($handle->written);
            $reset = RstStreamFrame::fromRaw($raw);
            static::assertSame(1, $reset->streamId);
            static::assertSame(ErrorCode::ProtocolError, $reset->errorCode);
        }

        $events = $connection->readEvent();
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertSame(3, $events[0]->streamId);
    }

    /**
     * @return iterable<string, array{Header}>
     */
    public static function malformedFields(): iterable
    {
        yield 'uppercase name' => [new Header('X-Test', 'value')];
        yield 'connection header' => [new Header('connection', 'close')];
        yield 'duplicate pseudo-header' => [new Header(':method', 'POST')];
        yield 'content-length' => [new Header('content-length', 'invalid')];
    }

    public function testDataOnHalfClosedStreamUsesStreamClosed(): void
    {
        $headers = new Encoder()->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $handle = new ChunkedHandle(
            Frame\encode(new HeadersFrame(1, $headers, true, true)->toRaw())
                . Frame\encode(new DataFrame(1, '', true)->toRaw()),
        );
        $connection = new ServerConnection($handle);
        $connection->readEvent();
        try {
            $connection->readEvent();
            static::fail('Expected a stream error.');
        } catch (StreamException $error) {
            static::assertSame(ErrorCode::StreamClosed, $error->errorCode);
            static::assertTrue($connection->isConnected());
            [$raw] = Frame\decode($handle->written);
            static::assertSame(ErrorCode::StreamClosed, RstStreamFrame::fromRaw($raw)->errorCode);
        }
    }

    public function testDataOnIdleStreamRemainsAConnectionError(): void
    {
        $handle = new ChunkedHandle(Frame\encode(new DataFrame(1, '', true)->toRaw()));
        $connection = new ServerConnection($handle);
        try {
            $connection->readEvent();
            static::fail('Expected a connection error.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            static::assertFalse($connection->isConnected());
            [$raw] = Frame\decode($handle->written);
            static::assertSame(ErrorCode::ProtocolError->value, GoAwayFrame::fromRaw($raw)->errorCode);
        }
    }
}
