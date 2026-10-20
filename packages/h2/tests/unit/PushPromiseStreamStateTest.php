<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\ErrorCode;
use Psl\H2\Event\PushPromiseReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\StreamState;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function strlen;
use function substr;

final class PushPromiseStreamStateTest extends TestCase
{
    #[DataProvider('fragmentedBlocks')]
    public function testRejectedPromiseCannotReuseItsStreamId(bool $continued): void
    {
        $encoder = new Encoder();
        $bad = $encoder->encode([new Header(':method', 'GET'), new Header(':scheme', 'https')]);
        $headers = [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')];
        $input = Frame\encode(new PushPromiseFrame(1, 2, $continued ? substr($bad, 0, 1) : $bad, !$continued)->toRaw());
        if ($continued) {
            $input .= Frame\encode(new ContinuationFrame(1, substr($bad, 1), true)->toRaw());
        }

        $input .= Frame\encode(new PushPromiseFrame(1, 4, $encoder->encode($headers), true)->toRaw());
        $input .= Frame\encode(new PushPromiseFrame(1, 2, $encoder->encode($headers), true)->toRaw());
        $handle = new ChunkedHandle($input);
        $client = new ClientConnection($handle);
        $client->sendHeaders(1, $headers, true);
        $offset = strlen($handle->written);
        if ($continued) {
            static::assertSame([], $client->readEvent());
        }

        try {
            $client->readEvent();
            static::fail('Expected malformed promised headers to reset the promised stream.');
        } catch (StreamException $error) {
            static::assertSame(2, $error->streamId);
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
            static::assertTrue($client->isConnected());
            static::assertSame(StreamState::Closed, $client->getStreamState(2));
            static::assertSame(2, $client->lastPeerStreamId());
            [$reset] = Frame\decode(substr($handle->written, $offset));
            static::assertSame(2, RstStreamFrame::fromRaw($reset)->streamId);
        }

        $events = $client->readEvent();
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);
        static::assertSame(4, $events[0]->promisedStreamId);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('must be greater than last peer stream ID 4');
        $client->readEvent();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function fragmentedBlocks(): iterable
    {
        yield 'PUSH_PROMISE' => [false];
        yield 'PUSH_PROMISE with CONTINUATION' => [true];
    }
}
