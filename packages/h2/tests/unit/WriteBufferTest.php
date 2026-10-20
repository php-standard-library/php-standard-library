<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\Configuration;
use Psl\H2\Frame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Header;
use Revolt\EventLoop;

use function strlen;

final class WriteBufferTest extends TestCase
{
    public function testWindowWaitFlushResetsBufferThreshold(): void
    {
        $input =
            Frame\encode(new SettingsFrame([Setting::InitialWindowSize->value => 0], false)->toRaw())
            . Frame\encode(new WindowUpdateFrame(1, 1)->toRaw());
        $handle = new ChunkedHandle($input);
        $connection = new ClientConnection($handle, new Configuration(writeBufferThreshold: 30));
        $connection->readEvent();
        $connection->sendHeaders(1, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $writtenBefore = strlen($handle->written);

        $connection->buffered(static function () use ($connection, $handle, $writtenBefore): void {
            $connection->ping('12345678');
            static::assertSame($writtenBefore, strlen($handle->written));

            EventLoop::queue(static function () use ($connection): void {
                $connection->readEvent();
            });
            $connection->waitForSendWindow(1, 1);
            static::assertSame($writtenBefore + 17, strlen($handle->written));

            $connection->ping('abcdefgh');
            static::assertSame($writtenBefore + 17, strlen($handle->written));
        });

        static::assertSame($writtenBefore + 34, strlen($handle->written));
    }
}
