<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Exception\ConnectionException;
use Psl\H2\Frame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\EmptyReadHandle;

use function substr;

use const Psl\H2\FRAME_HEADER_SIZE;

/**
 * A read that returns nothing before the end of the data does not close the connection.
 *
 * A TLS stream wakes a read for records that carry no application data, such as the session tickets a TLS 1.3
 * server sends after its handshake, and for a record that has only partly arrived; the read then returns nothing.
 */
final class EmptyReadTest extends TestCase
{
    /**
     * How many bytes of the SETTINGS payload arrive before the read that returns nothing.
     */
    private const int PAYLOAD_BYTES_BEFORE_EMPTY_READ = 10;

    public function testEmptyReadsBeforeTheFirstFrameDoNotCloseTheConnection(): void
    {
        $settings = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $handle = new EmptyReadHandle(['', '', Frame\encode($settings)]);
        $connection = new ClientConnection($handle);

        $events = $connection->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame(2, $handle->emptyReads);
        static::assertTrue($connection->isConnected());
    }

    public function testEmptyReadsInsideAFrameDoNotCloseTheConnection(): void
    {
        $values = [
            Setting::MaxConcurrentStreams->value => 100,
            Setting::InitialWindowSize->value => 68_560,
            Setting::MaxFrameSize->value => 68_608,
            Setting::EnableConnectProtocol->value => 1,
        ];
        $settings = Frame\encode(new SettingsFrame($values, false)->toRaw());
        $payloadStart = FRAME_HEADER_SIZE + self::PAYLOAD_BYTES_BEFORE_EMPTY_READ;
        $handle = new EmptyReadHandle([
            substr($settings, 0, FRAME_HEADER_SIZE - 1),
            '',
            substr($settings, FRAME_HEADER_SIZE - 1, $payloadStart - FRAME_HEADER_SIZE + 1),
            '',
            substr($settings, $payloadStart),
        ]);
        $connection = new ClientConnection($handle);

        $events = $connection->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame($values, $events[0]->settings);
        static::assertSame(2, $handle->emptyReads);
        static::assertTrue($connection->isConnected());
    }

    public function testTheEndOfTheDataInsideAFrameClosesTheConnection(): void
    {
        $settings = Frame\encode(new SettingsFrame([], false)->toRaw());
        $handle = new EmptyReadHandle(['', substr($settings, 0, FRAME_HEADER_SIZE - 1)]);
        $connection = new ClientConnection($handle);

        try {
            $connection->readEvent();
            static::fail('The end of the data did not close the connection.');
        } catch (ConnectionException) {
            static::assertTrue($handle->reachedEndOfDataSource());
            static::assertFalse($connection->isConnected());
        }
    }
}
