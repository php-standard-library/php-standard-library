<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Decoder;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

final class SendHeaderValidationTest extends TestCase
{
    #[DataProvider('connectionSpecificFields')]
    public function testRejectsConnectionSpecificRequestFields(Header $header): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        $this->expectException(StreamException::class);

        $sm->sendHeadersEncoded(1, [new Header(':method', 'GET'), $header]);
    }

    #[DataProvider('connectionSpecificFields')]
    public function testRejectsConnectionSpecificResponseFields(Header $header): void
    {
        $sm = self::createServer();

        $this->expectException(StreamException::class);

        $sm->sendResponseHeadersEncoded(1, '200', [$header]);
    }

    #[DataProvider('connectionSpecificFields')]
    public function testRejectsConnectionSpecificPushFields(Header $header): void
    {
        $sm = self::createServer();

        $this->expectException(StreamException::class);

        $sm->sendPushPromise(1, 2, [new Header(':method', 'GET'), $header]);
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidRequestFieldNames(string $name): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        try {
            $sm->sendHeadersEncoded(1, [new Header(':method', 'GET'), new Header($name, 'value')]);
            static::fail('Expected an invalid field name to be rejected.');
        } catch (StreamException $error) {
            static::assertSame(1, $error->streamId);
        }

        // The rejected field block must not have changed the HPACK encoder state.
        $frame = HeadersFrame::fromRaw(
            Frame\decode($sm->sendHeadersEncoded(1, [
                new Header(':method', 'GET'),
                new Header('x-valid', 'value'),
            ]))[0],
        );
        static::assertEquals(
            [new Header(':method', 'GET'), new Header('x-valid', 'value')],
            new Decoder()->decode($frame->headerBlockFragment),
        );
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidResponseFieldNames(string $name): void
    {
        $sm = self::createServer();

        $this->expectException(StreamException::class);

        $sm->sendResponseHeadersEncoded(1, '200', [new Header($name, 'value')]);
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidPushFieldNames(string $name): void
    {
        $sm = self::createServer();

        $this->expectException(StreamException::class);

        $sm->sendPushPromise(1, 2, [new Header(':method', 'GET'), new Header($name, 'value')]);
    }

    public function testAllowsTeTrailers(): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();

        static::assertNotSame('', $sm->sendHeadersEncoded(1, [
            new Header(':method', 'GET'),
            new Header('te', 'trailers'),
        ]));
    }

    /**
     * @return iterable<string, array{Header}>
     */
    public static function connectionSpecificFields(): iterable
    {
        yield 'connection' => [new Header('connection', 'close')];
        yield 'uppercase connection' => [new Decoder()->decode("\x00\x0aConnection\x05close")[0]];
        yield 'keep-alive' => [new Header('keep-alive', 'timeout=5')];
        yield 'proxy-connection' => [new Header('proxy-connection', 'close')];
        yield 'transfer-encoding' => [new Header('transfer-encoding', 'chunked')];
        yield 'upgrade' => [new Header('upgrade', 'h2c')];
        yield 'te gzip' => [new Header('te', 'gzip')];
        yield 'te empty' => [new Header('te', '')];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['x name'];
        yield 'colon inside' => ['x:name'];
        yield 'lone colon' => [':'];
        yield 'carriage return' => ["x\rname"];
        yield 'line feed' => ["x\nname"];
        yield 'NUL' => ["x\0name"];
        yield 'non-ASCII' => ["x\xc3\xbcname"];
        yield 'quote' => ['x"name'];
        yield 'brackets' => ['x[0]'];
    }

    private static function createServer(): StateMachine
    {
        $sm = new StateMachine(false);
        $sm->initialize();
        $block = new Encoder()->encode([
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $sm->receive(new HeadersFrame(1, $block, false, true)->toRaw());

        return $sm;
    }
}
