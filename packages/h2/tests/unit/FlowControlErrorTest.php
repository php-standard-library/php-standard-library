<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\Configuration;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Frame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class FlowControlErrorTest extends TestCase
{
    public function testInitialWindowSizeAboveMaximumSendsFlowControlError(): void
    {
        $handle = new ChunkedHandle(
            Frame\encode(
                new SettingsFrame([
                    Setting::InitialWindowSize->value => 2_147_483_648,
                ], false)->toRaw(),
            ),
        );
        $connection = new ServerConnection($handle);
        try {
            $connection->readEvent();
            static::fail('Expected a flow control error.');
        } catch (FlowControlException) {
            [$frame] = Frame\decode($handle->written);
            static::assertSame(ErrorCode::FlowControlError->value, GoAwayFrame::fromRaw($frame)->errorCode);
            static::assertFalse($connection->isConnected());
        }
    }

    public function testReceiveWindowExhaustionSendsFlowControlError(): void
    {
        $headers = new Encoder()->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $handle = new ChunkedHandle(
            Frame\encode(new HeadersFrame(1, $headers, false, true)->toRaw())
                . Frame\encode(new DataFrame(1, 'x', false)->toRaw()),
        );
        $connection = new ServerConnection($handle, new Configuration([Setting::InitialWindowSize->value => 0]));
        $connection->readEvent();

        try {
            $connection->readEvent();
            static::fail('Expected a flow control error.');
        } catch (FlowControlException) {
            [$frame] = Frame\decode($handle->written);
            static::assertSame(ErrorCode::FlowControlError->value, GoAwayFrame::fromRaw($frame)->errorCode);
            static::assertFalse($connection->isConnected());
        }
    }
}
