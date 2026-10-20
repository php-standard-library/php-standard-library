<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\DataFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function str_repeat;
use function strlen;
use function substr;

final class StreamErrorStateTest extends TestCase
{
    #[DataProvider('invalidHeaderBlocks')]
    public function testStreamErrorStillDecodesWholeFieldBlock(bool $continuation, bool $trailing): void
    {
        $encoder = new Encoder();
        $request = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        $shared = new Header('x-shared', 'value');
        $input = $trailing ? Frame\encode(new HeadersFrame(1, $encoder->encode($request), false, true)->toRaw()) : '';
        $block = $encoder->encode($trailing ? [$shared] : [...$request, $shared]);
        $input .= Frame\encode(
            new HeadersFrame(
                1,
                $continuation ? substr($block, 0, 1) : $block,
                false,
                !$continuation,
                $trailing ? null : 1,
                $trailing ? null : 16,
            )->toRaw(),
        );
        if ($continuation) {
            $input .= Frame\encode(new ContinuationFrame(1, substr($block, 1), true)->toRaw());
        }

        $headers = [...$request, $shared];
        $input .= Frame\encode(new HeadersFrame(3, $encoder->encode($headers), true, true)->toRaw());
        $handle = new ChunkedHandle($input);
        $connection = new ServerConnection($handle);
        if ($trailing) {
            $connection->readEvent();
        }

        if ($continuation) {
            static::assertSame([], $connection->readEvent());
        }

        try {
            $connection->readEvent();
            static::fail('Expected a stream error.');
        } catch (StreamException $error) {
            static::assertSame(1, $error->streamId);
            static::assertTrue($connection->isConnected());
        }

        $events = $connection->readEvent();
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertSame(3, $events[0]->streamId);
        static::assertEquals($headers, $events[0]->headers);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function invalidHeaderBlocks(): iterable
    {
        yield 'self-dependent HEADERS' => [false, false];
        yield 'self-dependent HEADERS with CONTINUATION' => [true, false];
        yield 'trailers without END_STREAM' => [false, true];
        yield 'trailers without END_STREAM with CONTINUATION' => [true, true];
    }

    public function testMalformedDataDoesNotDrainConnectionWindow(): void
    {
        $encoder = new Encoder();
        $headers = [
            new Header(':method', 'POST'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        $data = str_repeat('x', 16_384);
        $input = '';
        for ($streamId = 1; $streamId < 11; $streamId += 2) {
            $block = $encoder->encode([...$headers, new Header('content-length', '0')]);
            $input .= Frame\encode(new HeadersFrame($streamId, $block, false, true)->toRaw());
            $input .= Frame\encode(new DataFrame($streamId, $data, true)->toRaw());
        }

        $input .= Frame\encode(new HeadersFrame(11, $encoder->encode($headers), false, true)->toRaw());
        $input .= Frame\encode(new DataFrame(11, $data, true)->toRaw());
        $handle = new ChunkedHandle($input, 4096);
        $connection = new ServerConnection($handle);
        for ($streamId = 1; $streamId < 11; $streamId += 2) {
            $connection->readEvent();
            $offset = strlen($handle->written);
            try {
                $connection->readEvent();
                static::fail('Expected a content-length error.');
            } catch (StreamException $error) {
                static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
                static::assertTrue($connection->isConnected());
            }

            [$reset, $length] = Frame\decode(substr($handle->written, $offset));
            static::assertSame($streamId, RstStreamFrame::fromRaw($reset)->streamId);
            [$update] = Frame\decode(substr($handle->written, $offset + $length));
            $window = WindowUpdateFrame::fromRaw($update);
            static::assertSame(0, $window->streamId);
            static::assertSame(strlen($data), $window->windowSizeIncrement);
        }

        $connection->readEvent();
        $events = $connection->readEvent();
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame(11, $events[0]->streamId);
        static::assertSame($data, $events[0]->data);
        static::assertTrue($connection->isConnected());
    }

    public function testDataAfterResetRestoresConnectionWindow(): void
    {
        $encoder = new Encoder();
        $headers = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        $data = str_repeat('x', 16_384);
        $input = Frame\encode(new HeadersFrame(1, $encoder->encode($headers), true, true)->toRaw());
        for ($frame = 0; $frame < 5; $frame++) {
            $input .= Frame\encode(new DataFrame(1, $data, true)->toRaw());
        }

        $input .= Frame\encode(new HeadersFrame(3, $encoder->encode($headers), false, true)->toRaw());
        $input .= Frame\encode(new DataFrame(3, $data, true)->toRaw());
        $handle = new ChunkedHandle($input, 4096);
        $connection = new ServerConnection($handle);
        $connection->readEvent();
        for ($frame = 0; $frame < 5; $frame++) {
            $offset = strlen($handle->written);
            try {
                $connection->readEvent();
                static::fail('Expected a stream error.');
            } catch (StreamException $error) {
                static::assertSame(ErrorCode::StreamClosed, $error->errorCode);
                static::assertTrue($connection->isConnected());
            }

            [$reset, $length] = Frame\decode(substr($handle->written, $offset));
            static::assertSame(1, RstStreamFrame::fromRaw($reset)->streamId);
            [$update] = Frame\decode(substr($handle->written, $offset + $length));
            $window = WindowUpdateFrame::fromRaw($update);
            static::assertSame(0, $window->streamId);
            static::assertSame(strlen($data), $window->windowSizeIncrement);
        }

        $connection->readEvent();
        $events = $connection->readEvent();
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame(3, $events[0]->streamId);
        static::assertSame($data, $events[0]->data);
    }
}
