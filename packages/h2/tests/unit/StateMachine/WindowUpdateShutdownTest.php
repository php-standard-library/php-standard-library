<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use const Psl\H2\MAX_WINDOW_SIZE;

final class WindowUpdateShutdownTest extends TestCase
{
    public function testConnectionWindowOverflowShutsDown(): void
    {
        $headers = new Encoder()->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $handle = new ChunkedHandle(
            Frame\encode(new HeadersFrame(1, $headers, true, true)->toRaw())
                . Frame\encode(new WindowUpdateFrame(0, MAX_WINDOW_SIZE)->toRaw()),
        );
        $connection = new ServerConnection($handle);
        $connection->readEvent();
        try {
            $connection->readEvent();
            static::fail('Expected a connection flow control error.');
        } catch (FlowControlException) {
            static::assertFalse($connection->isConnected());
            [$frame] = Frame\decode($handle->written);
            $goaway = GoAwayFrame::fromRaw($frame);
            static::assertSame(ErrorCode::FlowControlError->value, $goaway->errorCode);
            static::assertSame(1, $goaway->lastStreamId);
        }
    }
}
