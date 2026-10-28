<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Frame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\EmptyReadHandle;
use Psl\IO;

use const Psl\H2\CONNECTION_PREFACE;

/**
 * A connection given a reader reads the bytes that reader already buffered before reading the transport.
 *
 * After an upgrade from HTTP/1.1, the reader that parsed the HTTP/1.1 message may already hold the first HTTP/2
 * bytes; skipping them would lose the peer's connection preface or SETTINGS.
 */
final class BufferedReaderTest extends TestCase
{
    /**
     * The end of an HTTP/1.1 message head.
     */
    private const string END_OF_HEAD = "\r\n\r\n";

    /**
     * The HTTP/1.1 response that switches a connection to HTTP/2.
     */
    private const string SWITCHING_PROTOCOLS = "HTTP/1.1 101 Switching Protocols\r\nConnection: Upgrade\r\nUpgrade: h2c";

    /**
     * The HTTP/1.1 request that asks to switch a connection to HTTP/2.
     */
    private const string UPGRADE_REQUEST = "GET / HTTP/1.1\r\nHost: example.com\r\nConnection: Upgrade, HTTP2-Settings\r\nUpgrade: h2c\r\nHTTP2-Settings: AAMAAABkAAQAAP__";

    public function testClientReadsTheFramesItsReaderBuffered(): void
    {
        $values = [Setting::MaxConcurrentStreams->value => 100];
        $settings = Frame\encode(new SettingsFrame($values, false)->toRaw());
        $handle = new EmptyReadHandle([self::SWITCHING_PROTOCOLS . self::END_OF_HEAD . $settings]);
        $reader = new IO\Reader($handle);
        static::assertSame(self::SWITCHING_PROTOCOLS, $reader->readUntil(self::END_OF_HEAD));

        $connection = new ClientConnection($handle, reader: $reader);
        $events = $connection->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame($values, $events[0]->settings);
    }

    public function testServerReadsThePrefaceAndFramesItsReaderBuffered(): void
    {
        $values = [Setting::InitialWindowSize->value => 65_535];
        $settings = Frame\encode(new SettingsFrame($values, false)->toRaw());
        $handle = new EmptyReadHandle([self::UPGRADE_REQUEST . self::END_OF_HEAD . CONNECTION_PREFACE . $settings]);
        $reader = new IO\Reader($handle);
        static::assertSame(self::UPGRADE_REQUEST, $reader->readUntil(self::END_OF_HEAD));

        $connection = new ServerConnection($handle, reader: $reader);
        $connection->readClientPreface();
        $events = $connection->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
        static::assertSame($values, $events[0]->settings);
    }
}
