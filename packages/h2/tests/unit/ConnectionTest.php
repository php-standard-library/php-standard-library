<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\H2\ClientConnection;
use Psl\H2\ErrorCode;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Event\PingReceived;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Event\StreamClosed;
use Psl\H2\Event\StreamReset;
use Psl\H2\Event\WindowUpdated;
use Psl\H2\Exception\ConnectionException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PingFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\ServerConfiguration;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\H2\Tests\Fixture\SlowDripStream;
use Psl\H2\Tests\Fixture\TimeoutOnReadHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Exception\ExceptionInterface;
use Psl\HPACK\Header;
use Psl\IO;
use RuntimeException;

use function count;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

use const Psl\H2\CONNECTION_PREFACE;

final class ConnectionTest extends TestCase
{
    public function testClientInitializeSendsPreface(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);
        $conn->initialize();

        $handle->seek(0);
        $written = $handle->readAll();

        static::assertStringStartsWith(CONNECTION_PREFACE, $written);
        static::assertGreaterThan(strlen(CONNECTION_PREFACE), strlen($written));
    }

    public function testServerInitializeDoesNotSendPreface(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ServerConnection($handle);
        $conn->initialize();

        $handle->seek(0);
        $written = $handle->readAll();

        static::assertStringStartsNotWith(CONNECTION_PREFACE, $written);
    }

    public function testReadEventProcessesSettingsFrame(): void
    {
        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $frameData = Frame\encode($settingsFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ClientConnection($handle);

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testReadEventWritesSettingsAck(): void
    {
        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $frameData = Frame\encode($settingsFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ClientConnection($handle);
        $conn->readEvent();

        $handle->seek(strlen($frameData));
        $ackData = $handle->readAll();

        static::assertGreaterThan(0, strlen($ackData));

        [$ackFrame, $_] = Frame\decode($ackData);
        static::assertSame(FrameType::Settings->value, $ackFrame->type);
        static::assertSame(0x01, $ackFrame->flags & 0x01);
    }

    public function testReadEventWritesPingAck(): void
    {
        $pingFrame = new PingFrame('12345678', false)->toRaw();
        $frameData = Frame\encode($pingFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);
        $conn->readEvent();

        $handle->seek(strlen($frameData));
        $ackData = $handle->readAll();

        static::assertGreaterThan(0, strlen($ackData));

        [$ackFrame, $_] = Frame\decode($ackData);
        static::assertSame(FrameType::Ping->value, $ackFrame->type);
        static::assertSame(0x01, $ackFrame->flags & 0x01);
    }

    public function testReadEventProcessesPing(): void
    {
        $pingFrame = new PingFrame('12345678', false)->toRaw();
        $frameData = Frame\encode($pingFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        /** @var non-empty-list<PingReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(PingReceived::class, $events[0]);
    }

    public function testReadEventReturnsMultipleEvents(): void
    {
        $server = new ServerConnection(new IO\MemoryHandle());
        $server->initialize();

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $dataRaw = new DataFrame(1, 'body', true)->toRaw();

        $headersData = Frame\encode($headersRaw);
        $dataData = Frame\encode($dataRaw);

        $handle = new IO\MemoryHandle($headersData . $dataData);
        $conn = new ServerConnection($handle);

        /** @var non-empty-list<HeadersReceived> $events1 */
        $events1 = $conn->readEvent();
        static::assertCount(1, $events1);
        static::assertInstanceOf(HeadersReceived::class, $events1[0]);

        /** @var non-empty-list<DataReceived> $events2 */
        $events2 = $conn->readEvent();
        static::assertGreaterThanOrEqual(1, count($events2));
        static::assertInstanceOf(DataReceived::class, $events2[0]);
        static::assertTrue($events2[0]->endStream);
    }

    public function testReadEventWritesWindowUpdateForData(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersRaw = new HeadersFrame(1, $block, false, true)->toRaw();
        $dataRaw = new DataFrame(1, 'hello', false)->toRaw();

        $frameData = Frame\encode($headersRaw) . Frame\encode($dataRaw);
        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        $conn->readEvent();
        $conn->readEvent();

        $handle->seek(strlen($frameData));
        $responseData = $handle->readAll();

        $windowUpdateCount = 0;
        $offset = 0;
        $responseLength = strlen($responseData);
        while ($offset < $responseLength) {
            [$frame, $offset] = Frame\decode($responseData, $offset);
            if ($frame->type === FrameType::WindowUpdate->value) {
                $windowUpdateCount++;
            }
        }

        static::assertSame(2, $windowUpdateCount);
    }

    public function testReadFrameParsesMultiByteLength(): void
    {
        $payload = str_repeat('x', 300);
        $raw = new Frame\RawFrame(FrameType::Data->value, 0x00, 1, $payload);
        $encoded = Frame\encode($raw);

        $handle = new IO\MemoryHandle($encoded);
        $conn = new ServerConnection($handle);

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());

        $handle2 = new IO\MemoryHandle($headersData . $encoded);
        $conn2 = new ServerConnection($handle2);
        $conn2->readEvent();

        /** @var non-empty-list<DataReceived> $events */
        $events = $conn2->readEvent();
        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DataReceived::class, $event);
        static::assertSame(300, strlen($event->data));
    }

    public function testReadEventClosedConnectionThrows(): void
    {
        $handle = new IO\MemoryHandle('');
        $conn = new ClientConnection($handle);

        $this->expectException(ConnectionException::class);
        $conn->readEvent();
    }

    public function testSendHeaders(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $streamId = $conn->nextStreamId();
        $conn->sendHeaders($streamId, [new Header(':method', 'GET')]);

        $handle->seek(0);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));
        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::Headers->value, $frame->type);
    }

    public function testSendHeadersWithEndStream(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $streamId = $conn->nextStreamId();
        $conn->sendHeaders($streamId, [new Header(':method', 'GET')], true);

        $handle->seek(0);
        $written = $handle->readAll();
        [$frame, $_] = Frame\decode($written);

        static::assertSame(0x01, $frame->flags & 0x01);
    }

    public function testSendData(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $streamId = $conn->nextStreamId();
        $conn->sendHeaders($streamId, [new Header(':method', 'POST')]);

        $handle->seek(0);
        $handle->readAll();
        $pos = $handle->tell();
        $conn->sendData($streamId, 'body data');

        $handle->seek($pos);
        $written = $handle->readAll();
        [$frame, $_] = Frame\decode($written);

        static::assertSame(FrameType::Data->value, $frame->type);
    }

    public function testSendPing(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $conn->ping('testping');

        $handle->seek(0);
        $written = $handle->readAll();
        [$decoded, $_] = Frame\decode($written);
        $parsed = PingFrame::fromRaw($decoded);

        static::assertInstanceOf(PingFrame::class, $parsed);
    }

    public function testSendGoAway(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $conn->goAway(ErrorCode::NoError, 'bye');

        $handle->seek(0);
        $written = $handle->readAll();
        [$frame, $_] = Frame\decode($written);

        static::assertSame(FrameType::GoAway->value, $frame->type);
    }

    public function testResetStream(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $streamId = $conn->nextStreamId();
        $conn->sendHeaders($streamId, [new Header(':method', 'GET')]);

        $handle->seek(0);
        $handle->readAll();
        $pos = $handle->tell();
        $conn->resetStream($streamId, ErrorCode::Cancel);

        $handle->seek($pos);
        $written = $handle->readAll();
        [$frame, $_] = Frame\decode($written);

        static::assertSame(FrameType::RstStream->value, $frame->type);
    }

    public function testNextStreamId(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        static::assertSame(1, $conn->nextStreamId());
        static::assertSame(3, $conn->nextStreamId());
    }

    public function testAvailableSendWindow(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        static::assertSame(65_535, $conn->availableSendWindow(1));
    }

    public function testReadEventWithWindowUpdate(): void
    {
        $windowUpdateRaw = new WindowUpdateFrame(0, 1000)->toRaw();
        $frameData = Frame\encode($windowUpdateRaw);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ClientConnection($handle);

        /** @var non-empty-list<WindowUpdated> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(WindowUpdated::class, $events[0]);
    }

    public function testReadEventReturnsMultipleEventsFromRstStream(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersEncoded = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());
        $rstEncoded = Frame\encode(new RstStreamFrame(1, ErrorCode::Cancel)->toRaw());

        $handle = new IO\MemoryHandle($headersEncoded . $rstEncoded);
        $conn = new ServerConnection($handle);

        $conn->readEvent();
        /** @var non-empty-list<StreamReset|StreamClosed> $events */
        $events = $conn->readEvent();

        static::assertCount(2, $events);
        static::assertInstanceOf(StreamReset::class, $events[0]);
        static::assertInstanceOf(StreamClosed::class, $events[1]);
    }

    public function testSendDataWithoutEndStreamDoesNotSetFlag(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $streamId = $conn->nextStreamId();
        $conn->sendHeaders($streamId, [new Header(':method', 'POST')]);

        $handle->seek(0);
        $handle->readAll();
        $pos = $handle->tell();
        $conn->sendData($streamId, 'body data');

        $handle->seek($pos);
        $written = $handle->readAll();
        [$frame, $_] = Frame\decode($written);

        static::assertSame(0, $frame->flags & 0x01);
    }

    public function testReadFrameParsesHighByteLength(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());

        $payload = str_repeat('A', 60_000);
        $raw = new Frame\RawFrame(FrameType::Data->value, 0x00, 1, $payload);
        $encoded = Frame\encode($raw);

        $handle = new IO\MemoryHandle($headersData . $encoded);
        $conn = new ServerConnection($handle, new ServerConfiguration([
            Setting::MaxFrameSize->value => 16_777_215,
        ]));
        $conn->readEvent();

        /** @var non-empty-list<DataReceived> $events */
        $events = $conn->readEvent();
        static::assertCount(1, $events);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame(60_000, strlen($events[0]->data));
    }

    public function testReadFrameWithChunkedInput(): void
    {
        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $frameData = Frame\encode($settingsFrame);

        $handle = new ChunkedHandle($frameData, 1);
        $conn = new ClientConnection($handle);

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testReadFrameChunkedWithMultiByteLength(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());

        $payload = str_repeat('B', 300);
        $dataFrame = Frame\encode(new Frame\RawFrame(FrameType::Data->value, 0x00, 1, $payload));

        $handle = new ChunkedHandle($headersData . $dataFrame, 3);
        $conn = new ServerConnection($handle);

        $conn->readEvent();
        /** @var non-empty-list<DataReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame(300, strlen($events[0]->data));
    }

    public function testInterleavedHeaderBlocksRejected(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header(':authority', 'localhost'),
        ]);

        $headersFrame1 = new HeadersFrame(1, $block, true, false)->toRaw();
        $headersFrame3 = new HeadersFrame(3, $block, true, true)->toRaw();

        $frameData = Frame\encode($headersFrame1) . Frame\encode($headersFrame3);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        $conn->readEvent();

        $this->expectException(ProtocolException::class);
        $conn->readEvent();
    }

    public function testSmallInitialWindowSizeAccepted(): void
    {
        $settingsPayload = pack('nN', 4, 1);
        $settingsFrame = new Frame\RawFrame(FrameType::Settings->value, 0, 0, $settingsPayload);
        $settingsData = Frame\encode($settingsFrame);

        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header(':authority', 'localhost'),
        ]);
        $headersFrame = new HeadersFrame(1, $block, false, true)->toRaw();
        $headersData = Frame\encode($headersFrame);

        $dataFrame = new DataFrame(1, str_repeat('A', 100), true)->toRaw();
        $dataData = Frame\encode($dataFrame);

        $handle = new ChunkedHandle($settingsData . $headersData . $dataData);
        $conn = new ServerConnection($handle);

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent();
        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);

        /** @var non-empty-list<HeadersReceived> $events */
        $events = $conn->readEvent();
        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    public function testConnectWithPathRejected(): void
    {
        $hpack = "\x00\x07:method\x07CONNECT\x00\x0a:authority\x0elocalhost:8080\x00\x05:path\x0a/something";

        $headersFrame = new Frame\RawFrame(FrameType::Headers->value, 0x01 | 0x04, 1, $hpack);
        $frameData = Frame\encode($headersFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        $this->expectException(ProtocolException::class);
        $conn->readEvent();
    }

    public function testRapidPriorityFramesIgnored(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
            new Header(':authority', 'localhost'),
        ]);
        $headersFrame = new HeadersFrame(1, $block, true, true)->toRaw();
        $frameData = Frame\encode($headersFrame);

        for ($i = 0; $i < 100; $i++) {
            $priority = pack('Nc', 0, $i % 256);
            $raw = new Frame\RawFrame(2, 0, 1, $priority);
            $frameData .= Frame\encode($raw);
        }

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        /** @var non-empty-list<HeadersReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    public function testHpackTruncatedStringSendsCompressionError(): void
    {
        $hpack = "\x82\x84\x87\x00\x0a\x68\x65\x6c\x6c\x6f\x01\x76";

        $headersFrame = new Frame\RawFrame(FrameType::Headers->value, 0x01 | 0x04, 1, $hpack);
        $frameData = Frame\encode($headersFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        $this->expectException(ExceptionInterface::class);
        $conn->readEvent();
    }

    public function testAvailableSendWindowReflectsMinOfConnectionAndStream(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $sid = $conn->nextStreamId();
        $conn->sendHeaders($sid, [new Header(':method', 'POST')]);

        static::assertSame(65_535, $conn->availableSendWindow($sid));

        $conn->sendData($sid, str_repeat('A', 1000), false);
        static::assertSame(65_535 - 1000, $conn->availableSendWindow($sid));

        $conn->sendData($sid, str_repeat('A', 64_535), false);
        static::assertSame(0, $conn->availableSendWindow($sid));
    }

    public function testServerRejectsPushPromiseFromClient(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);

        $pushPromiseFrame = new PushPromiseFrame(1, 2, $block, true)->toRaw();
        $frameData = Frame\encode($pushPromiseFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle);

        $this->expectException(ProtocolException::class);
        $conn->readEvent();
    }

    public function testRstStreamOnIdleStreamThrowsProtocolError(): void
    {
        $rstEncoded = Frame\encode(new RstStreamFrame(99, ErrorCode::Cancel)->toRaw());

        $handle = new IO\MemoryHandle($rstEncoded);
        $conn = new ServerConnection($handle);

        $this->expectException(ProtocolException::class);
        $conn->readEvent();
    }

    public function testHeaderTableSizeExceedingLimitThrowsProtocolError(): void
    {
        $settingsPayload = pack('nN', Setting::HeaderTableSize->value, 2_000_000);
        $settingsFrame = new Frame\RawFrame(FrameType::Settings->value, 0, 0, $settingsPayload);
        $settingsData = Frame\encode($settingsFrame);

        $handle = new IO\MemoryHandle($settingsData);
        $conn = new ServerConnection($handle);

        $this->expectException(ProtocolException::class);
        $conn->readEvent();
    }

    public function testHeaderTableSizeWithinLimitAccepted(): void
    {
        $settingsPayload = pack('nN', Setting::HeaderTableSize->value, 65_536);
        $settingsFrame = new Frame\RawFrame(FrameType::Settings->value, 0, 0, $settingsPayload);
        $settingsData = Frame\encode($settingsFrame);

        $handle = new IO\MemoryHandle($settingsData);
        $conn = new ServerConnection($handle);

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testReadClientPrefaceTimeoutIsIncremental(): void
    {
        $stream = new SlowDripStream(CONNECTION_PREFACE, Duration::milliseconds(30));
        $conn = new ServerConnection($stream);

        $start = Timestamp::monotonic();

        try {
            $conn->readClientPreface(new Async\TimeoutCancellationToken(Duration::milliseconds(300)));
            static::fail('Expected CancelledException for timeout');
        } catch (Async\Exception\CancelledException) {
            $elapsed = Timestamp::monotonic()->since($start)->getTotalMilliseconds();

            static::assertLessThan(1000, $elapsed);
        }
    }

    public function testBdpAutoTuningWithDefaultInitialWindowSize(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());
        $dataFrame = new DataFrame(1, 'hello', false)->toRaw();
        $frameData = $headersData . Frame\encode($dataFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle, new ServerConfiguration(maxReceiveWindowSize: 16_777_216));

        $conn->readEvent();
        $events = $conn->readEvent();

        static::assertNotNull($events);
        static::assertGreaterThanOrEqual(1, count($events));
        static::assertInstanceOf(DataReceived::class, $events[0]);
    }

    public function testBdpAutoTuningWithCustomInitialWindowSize(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());
        $dataFrame = new DataFrame(1, 'hello', false)->toRaw();
        $frameData = $headersData . Frame\encode($dataFrame);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ServerConnection($handle, new ServerConfiguration([
            Setting::InitialWindowSize->value => 16_384,
        ], maxReceiveWindowSize: 16_777_216));

        $conn->readEvent();
        $events = $conn->readEvent();

        static::assertNotNull($events);
        static::assertGreaterThanOrEqual(1, count($events));
        static::assertInstanceOf(DataReceived::class, $events[0]);
    }

    public function testCustomReaderIsUsed(): void
    {
        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 50], false)->toRaw();
        $frameData = Frame\encode($settingsFrame);

        $handle = new ChunkedHandle($frameData, 3);
        $reader = new IO\Reader($handle);
        $conn = new ClientConnection($handle, reader: $reader);

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testReadEventWithIoFailureThrowsIoException(): void
    {
        $handle = new TimeoutOnReadHandle('', timeoutAfterReads: 0);
        $conn = new ClientConnection($handle);

        $this->expectException(IO\Exception\RuntimeException::class);
        $conn->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(1)));
    }

    public function testReadEventWithIoFailureAndNoCancellationThrowsIoException(): void
    {
        $handle = new TimeoutOnReadHandle('', timeoutAfterReads: 0);
        $conn = new ClientConnection($handle);

        $this->expectException(IO\Exception\RuntimeException::class);
        $conn->readEvent();
    }

    public function testReadEventWithTimeoutThrowsOnNonTimeoutException(): void
    {
        $handle = new IO\MemoryHandle('');
        $conn = new ClientConnection($handle);

        $this->expectException(ConnectionException::class);
        $conn->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(1)));
    }

    public function testSendDataDefaultEndStreamIsFalse(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $sid = $conn->nextStreamId();
        $conn->sendHeaders($sid, [new Header(':method', 'POST')]);

        $handle->seek(0);
        $handle->readAll();
        $pos = $handle->tell();

        $conn->sendData($sid, 'test payload');

        $handle->seek($pos);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));

        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::Data->value, $frame->type);
        static::assertSame(0, $frame->flags & 0x01);
        static::assertSame('test payload', $frame->payload);
    }

    public function testSendDataWithEndStream(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $sid = $conn->nextStreamId();
        $conn->sendHeaders($sid, [new Header(':method', 'POST')]);

        $handle->seek(0);
        $handle->readAll();
        $pos = $handle->tell();

        $conn->sendData($sid, 'final', true);

        $handle->seek($pos);
        $written = $handle->readAll();

        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::Data->value, $frame->type);
        static::assertSame(0x01, $frame->flags & 0x01);
    }

    public function testReadClientPrefaceSucceedsWithChunkedInput(): void
    {
        $handle = new ChunkedHandle(CONNECTION_PREFACE, 3);
        $conn = new ServerConnection($handle);

        $conn->readClientPreface();

        static::assertTrue(true);
    }

    public function testReadClientPrefaceThrowsOnConnectionClose(): void
    {
        $partial = substr(CONNECTION_PREFACE, 0, 10);
        $handle = new IO\MemoryHandle($partial);
        $conn = new ServerConnection($handle);

        $this->expectException(ConnectionException::class);
        $conn->readClientPreface();
    }

    public function testReadClientPrefaceWithExtraDataPreservesBuffer(): void
    {
        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $extra = Frame\encode($settingsFrame);
        $handle = new IO\MemoryHandle(CONNECTION_PREFACE . $extra);
        $conn = new ServerConnection($handle);

        $conn->readClientPreface();

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent();
        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testReadFrameTimeoutOnlyAppliedWhenBufferEmpty(): void
    {
        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $frameData = Frame\encode($settingsFrame);

        $handle = new ChunkedHandle($frameData, 2);
        $conn = new ClientConnection($handle);

        /** @var non-empty-list<SettingsReceived> $events */
        $events = $conn->readEvent(new Async\TimeoutCancellationToken(Duration::seconds(5)));

        static::assertCount(1, $events);
        static::assertInstanceOf(SettingsReceived::class, $events[0]);
    }

    public function testReadClientPrefaceWithMultipleFollowingFrames(): void
    {
        $settings1 = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $settings2 = new SettingsFrame([Setting::MaxFrameSize->value => 16_384], false)->toRaw();
        $data = CONNECTION_PREFACE . Frame\encode($settings1) . Frame\encode($settings2);

        $handle = new ChunkedHandle($data);
        $conn = new ServerConnection($handle);

        $conn->readClientPreface();

        /** @var non-empty-list<SettingsReceived> $events1 */
        $events1 = $conn->readEvent();
        static::assertCount(1, $events1);
        static::assertInstanceOf(SettingsReceived::class, $events1[0]);

        /** @var non-empty-list<SettingsReceived> $events2 */
        $events2 = $conn->readEvent();
        static::assertCount(1, $events2);
        static::assertInstanceOf(SettingsReceived::class, $events2[0]);
    }

    public function testReadFrameParsesHighFirstBytePayloadLength(): void
    {
        $payloadSize = 70_000;
        $unknownPayload = str_repeat("\x00", $payloadSize);
        $unknownFrame = new Frame\RawFrame(0xFF, 0, 0, $unknownPayload);
        $unknownEncoded = Frame\encode($unknownFrame);

        $settingsFrame = new SettingsFrame([Setting::MaxConcurrentStreams->value => 100], false)->toRaw();
        $settingsEncoded = Frame\encode($settingsFrame);

        $handle = new IO\MemoryHandle($unknownEncoded . $settingsEncoded);
        $conn = new ServerConnection($handle, new ServerConfiguration([
            Setting::MaxFrameSize->value => 16_777_215,
        ]));

        $events1 = $conn->readEvent();
        static::assertSame([], $events1);

        /** @var non-empty-list<SettingsReceived> $events2 */
        $events2 = $conn->readEvent();
        static::assertCount(1, $events2);
        static::assertInstanceOf(SettingsReceived::class, $events2[0]);
    }

    public function testWindowUpdateStreamIdIsCorrectlyParsed(): void
    {
        $windowUpdateRaw = new WindowUpdateFrame(0, 5000)->toRaw();
        $frameData = Frame\encode($windowUpdateRaw);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ClientConnection($handle);

        /** @var non-empty-list<WindowUpdated> $events */
        $events = $conn->readEvent();

        static::assertCount(1, $events);
        static::assertInstanceOf(WindowUpdated::class, $events[0]);
        static::assertSame(0, $events[0]->streamId);
        static::assertSame(5000, $events[0]->increment);
    }

    public function testConnectionWindowUpdateIncreasesAvailableSendWindow(): void
    {
        $windowUpdateRaw = new WindowUpdateFrame(0, 10_000)->toRaw();
        $frameData = Frame\encode($windowUpdateRaw);

        $handle = new IO\MemoryHandle($frameData);
        $conn = new ClientConnection($handle);

        $initialWindow = $conn->availableSendWindow(0);
        $conn->readEvent();
        $newWindow = $conn->availableSendWindow(0);

        static::assertSame($initialWindow + 10_000, $newWindow);
    }

    public function testBufferedFlushesOnSuccess(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $conn->buffered(static function () use ($conn) {
            $sid = $conn->nextStreamId();
            $conn->sendHeaders($sid, [new Header(':method', 'GET')], true);
        });

        $handle->seek(0);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));
        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::Headers->value, $frame->type);
    }

    public function testBufferedFlushesOnException(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        try {
            $conn->buffered(static function () use ($conn) {
                $sid = $conn->nextStreamId();
                $conn->sendHeaders($sid, [new Header(':method', 'GET')], true);
                throw new RuntimeException('test error');
            });
        } catch (RuntimeException) {
            static::addToAssertionCount(1);
        }

        $handle->seek(0);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));
        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::Headers->value, $frame->type);
    }

    public function testStartBufferingAndFlushBuffer(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $conn->buffered(static function () use ($conn, $handle) {
            $sid1 = $conn->nextStreamId();
            $conn->sendHeaders($sid1, [new Header(':method', 'GET')], true);

            $handle->seek(0);
            $midWrite = $handle->readAll();
            static::assertSame(0, strlen($midWrite));

            $sid2 = $conn->nextStreamId();
            $conn->sendHeaders($sid2, [new Header(':method', 'POST')], true);
        });

        $handle->seek(0);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));

        [$frame1, $offset] = Frame\decode($written);
        static::assertSame(FrameType::Headers->value, $frame1->type);

        [$frame2, $_] = Frame\decode($written, $offset);
        static::assertSame(FrameType::Headers->value, $frame2->type);
    }

    public function testSendResponseHeaders(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());

        $handle = new IO\MemoryHandle($headersData);
        $conn = new ServerConnection($handle);

        $conn->readEvent();

        $pos = $handle->tell();
        $conn->sendHeadersWithStatus(1, '200', [new Header('content-type', 'text/plain')]);

        $handle->seek($pos);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));
        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::Headers->value, $frame->type);
        static::assertSame(1, $frame->streamId);
    }

    public function testSendPushPromise(): void
    {
        $encoder = new Encoder();
        $block = $encoder->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $headersData = Frame\encode(new HeadersFrame(1, $block, false, true)->toRaw());

        $handle = new IO\MemoryHandle($headersData);
        $conn = new ServerConnection($handle);

        $conn->readEvent();

        $pos = $handle->tell();
        $conn->sendPushPromise(1, 2, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/style.css'),
            new Header(':authority', 'localhost'),
        ]);

        $handle->seek($pos);
        $written = $handle->readAll();

        static::assertGreaterThan(0, strlen($written));
        [$frame, $_] = Frame\decode($written);
        static::assertSame(FrameType::PushPromise->value, $frame->type);
    }

    public function testLastPeerStreamId(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ServerConnection($handle);

        static::assertSame(0, $conn->lastPeerStreamId());
    }

    public function testBufferedAutoFlushesAt65KB(): void
    {
        $handle = new IO\MemoryHandle();
        $conn = new ClientConnection($handle);

        $conn->buffered(static function () use ($conn, $handle) {
            $sid = $conn->nextStreamId();
            $conn->sendHeaders($sid, [new Header(':method', 'POST')]);

            $handle->seek(0);
            $beforeData = $handle->readAll();
            static::assertSame(0, strlen($beforeData));

            $conn->sendData($sid, str_repeat('A', 65_535));

            $handle->seek(0);
            $afterData = $handle->readAll();
            static::assertGreaterThan(0, strlen($afterData));
        });
    }
}
