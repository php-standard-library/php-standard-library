<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\PushPromiseReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\StreamState;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

final class PushPromiseRulesTest extends TestCase
{
    public function testRejectsIdleAssociatedStream(): void
    {
        $sm = new StateMachine(true);
        $this->expectException(ProtocolException::class);
        $sm->receive(new PushPromiseFrame(1, 2, new Encoder()->encode(self::request()), true)->toRaw());
    }

    public function testRejectsHalfClosedRemoteAssociatedStream(): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request());
        $encoder = new Encoder();
        $sm->receive(new HeadersFrame(1, $encoder->encode([new Header(':status', '200')]), true, true)->toRaw());
        $this->expectException(ProtocolException::class);
        $sm->receive(new PushPromiseFrame(1, 2, $encoder->encode(self::request()), true)->toRaw());
    }

    public function testRejectsServerInitiatedAssociatedStream(): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request(), true);
        $encoder = new Encoder();
        $sm->receive(new PushPromiseFrame(1, 2, $encoder->encode(self::request()), true)->toRaw());
        $sm->receive(new HeadersFrame(2, $encoder->encode([new Header(':status', '200')]), false, true)->toRaw());
        $this->expectException(ProtocolException::class);
        $sm->receive(new PushPromiseFrame(2, 4, $encoder->encode(self::request()), true)->toRaw());
    }

    /**
     * @param list<Header> $headers
     */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidPromisedRequest(array $headers, bool $continued): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request(), true);
        $encoder = new Encoder();
        $block = $encoder->encode($headers);
        try {
            if ($continued) {
                static::assertSame(
                    [[], []],
                    $sm->receive(new PushPromiseFrame(1, 2, substr($block, 0, 1), false)->toRaw()),
                );
                $sm->receive(new ContinuationFrame(1, substr($block, 1), true)->toRaw());
            } else {
                $sm->receive(new PushPromiseFrame(1, 2, $block, true)->toRaw());
            }

            static::fail('Expected a promised-stream error.');
        } catch (StreamException $error) {
            static::assertSame(2, $error->streamId);
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
        }

        $sm->resetStream(2, ErrorCode::ProtocolError);
        [, $events] = $sm->receive(new PushPromiseFrame(1, 4, $encoder->encode(self::request()), true)->toRaw());
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);
        static::assertSame(4, $events[0]->promisedStreamId);
    }

    #[DataProvider('validRequests')]
    public function testAcceptsSafeCacheableRequests(string $method, bool $endStream): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request(), $endStream);
        $headers = [...self::request($method), new Header('content-length', '00')];
        [, $events] = $sm->receive(new PushPromiseFrame(1, 2, new Encoder()->encode($headers), true)->toRaw());
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);
        static::assertSame(StreamState::ReservedRemote, $sm->getStreamState(2));
    }

    public function testDiscardsPromiseQueuedBeforeReset(): void
    {
        $sm = new StateMachine(true);
        $sm->sendHeadersEncoded(1, self::request(), true);
        $sm->resetStream(1, ErrorCode::Cancel);
        $encoder = new Encoder();
        $headers = [...self::request(), new Header('x-push', 'stored')];
        [$frames, $events] = $sm->receive(new PushPromiseFrame(1, 2, $encoder->encode($headers), true)->toRaw());
        static::assertSame([], $events);
        static::assertSame(ErrorCode::Cancel, RstStreamFrame::fromRaw($frames[0])->errorCode);
        static::assertSame(StreamState::Closed, $sm->getStreamState(2));
        $sm->sendHeadersEncoded(3, self::request(), true);
        [, $events] = $sm->receive(new PushPromiseFrame(3, 4, $encoder->encode($headers), true)->toRaw());
        static::assertInstanceOf(PushPromiseReceived::class, $events[0]);
        static::assertEquals($headers, $events[0]->headers);
    }

    /**
     * @return list<Header>
     */
    private static function request(string $method = 'GET', null|string $authority = 'example.com'): array
    {
        $headers = [new Header(':method', $method), new Header(':scheme', 'https'), new Header(':path', '/')];
        if ($authority !== null) {
            $headers[] = new Header(':authority', $authority);
        }

        return $headers;
    }

    /**
     * @return iterable<string, array{list<Header>, bool}>
     */
    public static function invalidRequests(): iterable
    {
        foreach ([false, true] as $continued) {
            $suffix = $continued ? ' continued' : '';
            foreach (['POST', 'PUT', 'DELETE', 'OPTIONS', 'TRACE', 'CUSTOM'] as $method) {
                yield $method . $suffix => [self::request($method), $continued];
            }

            yield 'missing authority' . $suffix => [self::request('GET', null), $continued];
            yield 'empty authority' . $suffix => [self::request('GET', ''), $continued];
            foreach (['1', '0001', '18446744073709551616', '-1', 'invalid'] as $length) {
                yield 'content length ' . $length . $suffix => [
                    [...self::request(), new Header('content-length', $length)],
                    $continued,
                ];
            }
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validRequests(): iterable
    {
        yield 'GET open' => ['GET', false];
        yield 'GET half-closed local' => ['GET', true];
        yield 'HEAD open' => ['HEAD', false];
        yield 'HEAD half-closed local' => ['HEAD', true];
    }
}
