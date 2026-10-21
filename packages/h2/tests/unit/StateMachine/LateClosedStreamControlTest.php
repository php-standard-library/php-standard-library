<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function pack;

final class LateClosedStreamControlTest extends TestCase
{
    #[DataProvider('closure')]
    public function testLateWindowUpdateAndResetAreIgnored(string $reason): void
    {
        $state = new StateMachine(false);
        $block = new Encoder()->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $state->receive(new HeadersFrame(1, $block, true, true)->toRaw());
        if ($reason === 'local reset') {
            $state->resetStream(1, ErrorCode::Cancel);
        } elseif ($reason === 'peer reset') {
            $state->receive(new RstStreamFrame(1, ErrorCode::Cancel)->toRaw());
        } else {
            $state->sendHeadersEncoded(1, [new Header(':status', '200')], true);
        }

        static::assertSame([[], []], $state->receive(new RawFrame(8, 0, 1, pack('N', 1))));
        static::assertSame([[], []], $state->receive(new RawFrame(8, 0, 1, pack('N', 0))));
        static::assertSame([[], []], $state->receive(new RstStreamFrame(1, ErrorCode::Cancel)->toRaw()));
        static::assertFalse($state->shutdown);
    }

    public function testClientToleratesLateControlFramesOnItsResetStream(): void
    {
        $client = new StateMachine(true);
        $client->initialize();
        $client->sendHeadersEncoded(
            1,
            [
                new Header(':method', 'GET'),
                new Header(':scheme', 'https'),
                new Header(':authority', 'example.com'),
                new Header(':path', '/'),
            ],
            true,
        );
        $client->resetStream(1, ErrorCode::Cancel);

        // The peer sent these before it saw our RST_STREAM (RFC 9113 Section 5.1).
        static::assertSame([[], []], $client->receive(new RawFrame(8, 0, 1, pack('N', 1))));
        static::assertSame([[], []], $client->receive(new RstStreamFrame(1, ErrorCode::Cancel)->toRaw()));
        static::assertFalse($client->shutdown);

        // An unopened local stream is still an idle-stream connection error.
        try {
            $client->receive(new RawFrame(8, 0, 5, pack('N', 1)));
            static::fail('A control frame on an unopened local stream must be a connection error.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function closure(): iterable
    {
        yield 'local reset' => ['local reset'];
        yield 'peer reset' => ['peer reset'];
        yield 'end stream' => ['end stream'];
    }
}
