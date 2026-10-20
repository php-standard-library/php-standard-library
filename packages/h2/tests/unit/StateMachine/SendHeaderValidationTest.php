<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Exception\StreamException;
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
