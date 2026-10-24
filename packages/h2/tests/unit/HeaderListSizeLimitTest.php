<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\Configuration;
use Psl\H2\ConnectionInterface;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Exception\HeaderListSizeException;
use Psl\HPACK\Header;

use function chr;
use function str_repeat;
use function strlen;
use function substr;

use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;
use const Psl\H2\DEFAULT_MAX_HEADER_LIST_SIZE;
use const Psl\H2\DEFAULT_MAX_RECEIVED_HEADER_LIST_SIZE;

/**
 * One byte of a field block can stand for a dynamic table entry of up to 4 KiB, so a block well within the
 * block size limit can decode to a field section many times larger. Without a configured
 * SETTINGS_MAX_HEADER_LIST_SIZE, the decoder now bounds received field sections to 64 KiB.
 */
final class HeaderListSizeLimitTest extends TestCase
{
    /**
     * The length of the value of the field that fills the dynamic table: with its one-byte name and the 32-byte
     * overhead, the entry takes 4033 of the table's 4096 bytes.
     */
    private const int INDEXED_VALUE_LENGTH = 4_000;

    /**
     * The HPACK index of the first dynamic table entry, after the 61 static entries.
     */
    private const int FIRST_DYNAMIC_INDEX = 62;

    /**
     * The decoded size of each field that references the table entry: name, value and overhead.
     */
    private const int INDEXED_FIELD_SIZE = 1 + self::INDEXED_VALUE_LENGTH + 32;

    /**
     * The decoded size of the request pseudo-fields `:method GET`, `:scheme https` and `:path /`.
     */
    private const int REQUEST_PSEUDO_FIELDS_SIZE = 7 + 3 + 32 + 7 + 5 + 32 + (5 + 1 + 32);

    /**
     * The decoded size of the response pseudo-field `:status 200`.
     */
    private const int RESPONSE_PSEUDO_FIELD_SIZE = 7 + 3 + 32;

    public function testDefaultServerConnectionRejectsAFieldSectionThatDecodesPastTheLimit(): void
    {
        $references = self::referencesWithin(DEFAULT_MAX_RECEIVED_HEADER_LIST_SIZE - self::REQUEST_PSEUDO_FIELDS_SIZE);
        $accepted = self::requestBlock($references);
        $rejected = self::requestBlock($references + 1);
        static::assertLessThan(DEFAULT_MAX_HEADER_BLOCK_SIZE / 8, strlen($rejected));

        $connection = new ServerConnection(self::handle($accepted));
        $event = $this->readHeaders($connection);
        static::assertCount(3 + $references + 1, $event->headers);

        $handle = self::handle($rejected);
        $connection = new ServerConnection($handle);
        $this->assertRejected($connection, $handle, 0);
    }

    public function testDefaultClientConnectionRejectsAFieldSectionThatDecodesPastTheLimit(): void
    {
        $references = self::referencesWithin(DEFAULT_MAX_RECEIVED_HEADER_LIST_SIZE - self::RESPONSE_PSEUDO_FIELD_SIZE);

        $connection = new ClientConnection(self::handle(self::responseBlock($references)));
        $connection->sendHeaders($connection->nextStreamId(), self::request(), true);
        $event = $this->readHeaders($connection);
        static::assertCount(1 + $references + 1, $event->headers);

        $handle = self::handle(self::responseBlock($references + 1));
        $connection = new ClientConnection($handle);
        $connection->sendHeaders($connection->nextStreamId(), self::request(), true);
        $this->assertRejected($connection, $handle, strlen($handle->written));
    }

    public function testConfiguredSettingIsEnforcedAndAdvertised(): void
    {
        $block = self::requestBlock(2);
        $limit = self::REQUEST_PSEUDO_FIELDS_SIZE + (2 * self::INDEXED_FIELD_SIZE);

        $handle = self::handle($block);
        $connection = new ServerConnection($handle, new Configuration(settings: [
            Setting::MaxHeaderListSize->value => $limit,
        ]));
        $connection->initialize();
        [$settings] = Frame\decode($handle->written);
        static::assertSame($limit, SettingsFrame::fromRaw($settings)->settings[Setting::MaxHeaderListSize->value]);
        $this->assertRejected($connection, $handle, strlen($handle->written));

        $connection = new ServerConnection(self::handle($block), new Configuration(settings: [
            Setting::MaxHeaderListSize->value => $limit + self::INDEXED_FIELD_SIZE,
        ]));
        static::assertCount(6, $this->readHeaders($connection)->headers);
    }

    public function testTheDefaultLimitIsNotAdvertisedAndTheSettingsDefaultLiftsIt(): void
    {
        $references = self::referencesWithin(DEFAULT_MAX_RECEIVED_HEADER_LIST_SIZE - self::REQUEST_PSEUDO_FIELDS_SIZE);
        $block = self::requestBlock($references + 1);

        $handle = self::handle($block);
        $connection = new ServerConnection($handle);
        $connection->initialize();
        [$settings] = Frame\decode($handle->written);
        static::assertArrayNotHasKey(Setting::MaxHeaderListSize->value, SettingsFrame::fromRaw($settings)->settings);

        $handle = self::handle($block);
        $connection = new ServerConnection($handle, new Configuration(settings: [
            Setting::MaxHeaderListSize->value => DEFAULT_MAX_HEADER_LIST_SIZE,
        ]));
        $connection->initialize();
        [$settings] = Frame\decode($handle->written);
        static::assertArrayNotHasKey(Setting::MaxHeaderListSize->value, SettingsFrame::fromRaw($settings)->settings);
        static::assertCount(3 + $references + 2, $this->readHeaders($connection)->headers);
    }

    /**
     * Read events until the connection delivers the headers of the block.
     */
    private function readHeaders(ConnectionInterface $connection): HeadersReceived
    {
        $events = [];
        while ($events === []) {
            $events = $connection->readEvent();
        }

        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(HeadersReceived::class, $event);
        static::assertTrue($connection->isConnected());

        return $event;
    }

    /**
     * Check that reading the block fails it with a COMPRESSION_ERROR connection error caused by the HPACK list
     * size limit, and that a GOAWAY with that code went out.
     *
     * @param int<0, max> $bytesWrittenBefore Bytes the connection wrote before reading, which precede the GOAWAY.
     */
    private function assertRejected(
        ConnectionInterface $connection,
        ChunkedHandle $handle,
        int $bytesWrittenBefore,
    ): void {
        try {
            $events = [];
            while ($events === []) {
                $events = $connection->readEvent();
            }

            static::fail('Expected the field section size limit to end the connection.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::CompressionError, $error->errorCode);
            static::assertInstanceOf(HeaderListSizeException::class, $error->getPrevious());
            static::assertFalse($connection->isConnected());

            $wire = substr($handle->written, $bytesWrittenBefore);
            [$goAway, $consumed] = Frame\decode($wire);
            static::assertSame(strlen($wire), $consumed);
            static::assertSame(ErrorCode::CompressionError->value, GoAwayFrame::fromRaw($goAway)->errorCode);
        }
    }

    /**
     * The most references to the table entry that fit in the given budget, after the field that creates it.
     *
     * @param int<1, max> $budget
     *
     * @return int<0, max>
     */
    private static function referencesWithin(int $budget): int
    {
        return (int) ($budget / self::INDEXED_FIELD_SIZE) - 1;
    }

    /**
     * A HEADERS frame with END_HEADERS carrying the given block on stream 1.
     */
    private static function handle(string $block): ChunkedHandle
    {
        return new ChunkedHandle(Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw()), 1 << 20);
    }

    /**
     * A request field block: `:method GET`, `:scheme https` and `:path /` from the static table, a literal
     * field that fills the dynamic table with one 4 KiB entry, and the given number of one-byte references to it.
     *
     * @param int<0, max> $references
     */
    private static function requestBlock(int $references): string
    {
        return "\x82\x87\x84" . self::fillingFieldAndReferences($references);
    }

    /**
     * A response field block: `:status 200` from the static table, then the same filling field and references.
     *
     * @param int<0, max> $references
     */
    private static function responseBlock(int $references): string
    {
        return "\x88" . self::fillingFieldAndReferences($references);
    }

    /**
     * @param int<0, max> $references
     */
    private static function fillingFieldAndReferences(int $references): string
    {
        $literalWithIndexing = "\x40" . self::string('x') . self::string(str_repeat('a', self::INDEXED_VALUE_LENGTH));

        return $literalWithIndexing . str_repeat(chr(0x80 | self::FIRST_DYNAMIC_INDEX), $references);
    }

    /**
     * @return list<Header>
     */
    private static function request(): array
    {
        return [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header(':authority', 'example.com'),
        ];
    }

    /**
     * An HPACK string literal without Huffman coding.
     */
    private static function string(string $value): string
    {
        $length = strlen($value);
        if ($length < 127) {
            return chr($length) . $value;
        }

        $encoded = chr(127);
        $length -= 127;
        while ($length >= 128) {
            $encoded .= chr(($length & 0x7F) | 0x80);
            $length >>= 7;
        }

        return $encoded . chr($length) . $value;
    }
}
