<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PriorityFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\ServerConnection;
use Psl\H2\StreamState;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class IdlePriorityTest extends TestCase
{
    public function testSelfDependencyDoesNotResetIdleStream(): void
    {
        $priority = Frame\encode(new PriorityFrame(1, 1, 16, false)->toRaw());
        $block = new Encoder()->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $handle = new ChunkedHandle(
            $priority . Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw()) . $priority,
        );
        $connection = new ServerConnection($handle);
        static::assertSame([], $connection->readEvent());
        static::assertSame('', $handle->written);
        static::assertSame(StreamState::Idle, $connection->getStreamState(1));
        $connection->readEvent();
        try {
            $connection->readEvent();
            static::fail('Expected a stream error after HEADERS opened the stream.');
        } catch (StreamException $error) {
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            [$frame] = Frame\decode($handle->written);
            static::assertSame(ErrorCode::ProtocolError, RstStreamFrame::fromRaw($frame)->errorCode);
            static::assertTrue($connection->isConnected());
        }
    }
}
