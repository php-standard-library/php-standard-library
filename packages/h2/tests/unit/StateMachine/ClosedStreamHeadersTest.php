<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\StreamState;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

final class ClosedStreamHeadersTest extends TestCase
{
    #[DataProvider('closedStreams')]
    public function testClosedHeadersAreDecodedAndDiscarded(bool $client, bool $peerReset, bool $continued): void
    {
        $sm = new StateMachine($client);
        $encoder = new Encoder();
        $headers = $client ? [new Header(':status', '200')] : self::request();
        if ($client) {
            $sm->sendHeadersEncoded(1, self::request(), true);
        } else {
            $sm->receive(new HeadersFrame(1, $encoder->encode($headers), false, true)->toRaw());
        }

        if ($peerReset) {
            $sm->receive(new RstStreamFrame(1, ErrorCode::Cancel)->toRaw());
        } else {
            $sm->resetStream(1, ErrorCode::Cancel);
        }

        $fields = [...$headers, new Header('x-discarded', 'stored-in-dynamic-table')];
        $block = $encoder->encode($fields);
        if ($continued) {
            static::assertSame([[], []], $sm->receive(new HeadersFrame(1, substr($block, 0, 1), true, false)->toRaw()));
            $result = $sm->receive(new ContinuationFrame(1, substr($block, 1), true)->toRaw());
        } else {
            $result = $sm->receive(new HeadersFrame(1, $block, true, true)->toRaw());
        }

        static::assertSame([[], []], $result);
        static::assertSame(StreamState::Closed, $sm->getStreamState(1));
        static::assertSame(0, $sm->activeStreamCount());
        if ($client) {
            $sm->sendHeadersEncoded(3, self::request(), true);
        }

        [, $events] = $sm->receive(new HeadersFrame(3, $encoder->encode($fields), true, true)->toRaw());
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals($fields, $events[0]->headers);
    }

    public function testResetDuringContinuationDiscardsHeadersAndPendingError(): void
    {
        foreach ([false, true] as $client) {
            $sm = new StateMachine($client);
            $encoder = new Encoder();
            if ($client) {
                $sm->sendHeadersEncoded(1, self::request(), true);
            }

            $headers = $client ? [new Header(':status', '200')] : self::request();
            $headers[] = new Header('x-discarded', 'stored-in-dynamic-table');
            $block = $encoder->encode($headers);
            $sm->receive(new HeadersFrame(1, substr($block, 0, 1), true, false, 1, 16)->toRaw());
            $sm->resetStream(1, ErrorCode::Cancel);
            static::assertSame([[], []], $sm->receive(new ContinuationFrame(1, substr($block, 1), true)->toRaw()));
            static::assertSame(StreamState::Closed, $sm->getStreamState(1));
            if ($client) {
                $sm->sendHeadersEncoded(3, self::request(), true);
            }

            [, $events] = $sm->receive(new HeadersFrame(3, $encoder->encode($headers), true, true)->toRaw());
            static::assertInstanceOf(HeadersReceived::class, $events[0]);
            static::assertEquals($headers, $events[0]->headers);
        }
    }

    public function testClosedHeadersStillReportCompressionErrors(): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request(), true);
        $sm->resetStream(1, ErrorCode::Cancel);
        try {
            $sm->receive(new HeadersFrame(1, "\xff", true, true)->toRaw());
            static::fail('Expected a compression error.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::CompressionError, $error->errorCode);
        }
    }

    public function testDiscardedHeadersDoNotNeedValidHttpFields(): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request(), true);
        $sm->resetStream(1, ErrorCode::Cancel);
        $block = new Encoder()->encode([new Header('x-discard', 'value')]);
        static::assertSame([[], []], $sm->receive(new HeadersFrame(1, $block, true, true)->toRaw()));
    }

    /**
     * @return list<Header>
     */
    private static function request(): array
    {
        return [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')];
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function closedStreams(): iterable
    {
        foreach ([false, true] as $client) {
            foreach ([false, true] as $peerReset) {
                foreach ([false, true] as $continued) {
                    yield ($client ? 'client ' : 'server ')
                        . ($peerReset ? 'peer reset ' : 'local reset ')
                        . ($continued ? 'continued' : 'complete') => [$client, $peerReset, $continued];
                }
            }
        }
    }
}
