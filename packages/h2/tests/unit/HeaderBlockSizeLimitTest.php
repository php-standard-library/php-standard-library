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
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Header;

use function chr;
use function count;
use function str_repeat;
use function str_split;
use function strlen;
use function substr;

use const Psl\H2\DEFAULT_MAX_FRAME_SIZE;
use const Psl\H2\DEFAULT_MAX_HEADER_BLOCK_SIZE;
use const Psl\H2\DEFAULT_MAX_HEADER_LIST_SIZE;
use const Psl\H2\FRAME_HEADER_SIZE;

/**
 * A HEADERS frame without END_HEADERS is followed by CONTINUATION frames whose fragments are buffered until the
 * block ends. Without a limit, a peer that never ends the block makes the connection buffer without bound
 * (the CVE-2023-45288 pattern). Every connection now limits the block to 64 KiB by default.
 */
final class HeaderBlockSizeLimitTest extends TestCase
{
    /**
     * The frames a never-ending field block arrives in: each carries a full frame of fragment, so the default
     * limit admits exactly four of them, and the fifth is the one that would exceed it.
     */
    private const int FRAMES_WITHIN_LIMIT = DEFAULT_MAX_HEADER_BLOCK_SIZE / DEFAULT_MAX_FRAME_SIZE;

    public function testDefaultServerConnectionRejectsANeverEndingHeaderBlock(): void
    {
        $handle = new ChunkedHandle(self::neverEndingBlock(1), 1 << 20);
        $connection = new ServerConnection($handle);

        $this->assertRejectedAtTheFrameThatWouldExceedTheLimit($connection, $handle);
    }

    public function testDefaultClientConnectionRejectsANeverEndingHeaderBlock(): void
    {
        $handle = new ChunkedHandle(self::neverEndingBlock(1), 1 << 20);
        $connection = new ClientConnection($handle);
        $connection->sendHeaders($connection->nextStreamId(), self::request(), true);
        $requestBytes = strlen($handle->written);

        $this->assertRejectedAtTheFrameThatWouldExceedTheLimit($connection, $handle, $requestBytes);
    }

    public function testConfiguredLimitRejectsASingleOversizedHeadersFrame(): void
    {
        $handle = new ChunkedHandle(
            Frame\encode(new HeadersFrame(1, str_repeat('a', 2_048), false, false)->toRaw()),
            1 << 20,
        );
        $connection = new ServerConnection($handle, new Configuration(maxHeaderBlockSize: 1_024));

        try {
            $connection->readEvent();
            static::fail('Expected the header block limit to end the connection.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            static::assertSame(
                'HTTP/2 connection error: Header block size exceeds maximum of 1024 bytes.',
                $error->getMessage(),
            );
            static::assertFalse($connection->isConnected());
        }
    }

    public function testZeroLiftsTheLimit(): void
    {
        $value = str_repeat('a', 70_000);
        $block = "\x82\x87\x84\x00" . self::string('x-large') . self::string($value);
        $fragments = str_split($block, DEFAULT_MAX_FRAME_SIZE);
        $input = '';
        foreach ($fragments as $index => $fragment) {
            $endHeaders = $index === (count($fragments) - 1);
            $input .= Frame\encode(
                $index === 0
                    ? new HeadersFrame(1, $fragment, false, $endHeaders)->toRaw()
                    : new ContinuationFrame(1, $fragment, $endHeaders)->toRaw(),
            );
        }

        static::assertGreaterThan(DEFAULT_MAX_HEADER_BLOCK_SIZE, strlen($block));

        $handle = new ChunkedHandle($input, 1 << 20);
        $connection = new ServerConnection($handle, new Configuration(settings: [
            Setting::MaxHeaderListSize->value => DEFAULT_MAX_HEADER_LIST_SIZE,
        ], maxHeaderBlockSize: 0));

        $events = [];
        while ($events === []) {
            $events = $connection->readEvent();
        }

        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(HeadersReceived::class, $event);
        static::assertCount(4, $event->headers);
        static::assertSame('x-large', $event->headers[3]->name);
        static::assertSame($value, $event->headers[3]->value);
        static::assertTrue($connection->isConnected());
    }

    /**
     * Read events until the connection rejects the block, and check that it did so at the first frame whose
     * fragment would take the buffered block past the limit, with a PROTOCOL_ERROR GOAWAY, having read no later
     * frame from the transport.
     *
     * @param int<0, max> $bytesWrittenBefore Bytes the connection wrote before reading, which precede the GOAWAY.
     */
    private function assertRejectedAtTheFrameThatWouldExceedTheLimit(
        ConnectionInterface $connection,
        ChunkedHandle $handle,
        int $bytesWrittenBefore = 0,
    ): void {
        $frameLength = FRAME_HEADER_SIZE + DEFAULT_MAX_FRAME_SIZE;
        for ($frame = 0; $frame <= self::FRAMES_WITHIN_LIMIT; $frame++) {
            try {
                static::assertSame([], $connection->readEvent());
                static::assertLessThan(self::FRAMES_WITHIN_LIMIT, $frame);
            } catch (ProtocolException $error) {
                static::assertSame(self::FRAMES_WITHIN_LIMIT, $frame);
                static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
                static::assertSame(
                    'HTTP/2 connection error: Header block size exceeds maximum of '
                    . DEFAULT_MAX_HEADER_BLOCK_SIZE
                    . ' bytes.',
                    $error->getMessage(),
                );
                static::assertFalse($connection->isConnected());
                static::assertSame(($frame + 1) * $frameLength, $handle->readPos);

                $wire = substr($handle->written, $bytesWrittenBefore);
                [$goAway, $consumed] = Frame\decode($wire);
                static::assertSame(strlen($wire), $consumed);
                static::assertSame(ErrorCode::ProtocolError->value, GoAwayFrame::fromRaw($goAway)->errorCode);

                return;
            }
        }

        static::fail('Expected the header block limit to end the connection.');
    }

    /**
     * A HEADERS frame without END_HEADERS followed by CONTINUATION frames that never set it, each carrying a
     * full frame of fragment, twice as many as the default limit admits.
     *
     * @param positive-int $streamId
     */
    private static function neverEndingBlock(int $streamId): string
    {
        $fragment = str_repeat('a', DEFAULT_MAX_FRAME_SIZE);
        $input = Frame\encode(new HeadersFrame($streamId, $fragment, false, false)->toRaw());
        for ($i = 1; $i < (2 * self::FRAMES_WITHIN_LIMIT); $i++) {
            $input .= Frame\encode(new ContinuationFrame($streamId, $fragment, false)->toRaw());
        }

        return $input;
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
