<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;
use Psl\HPACK\Internal\IntegerCodec;

use function str_repeat;

final class InitialHeaderTableTest extends TestCase
{
    public function testLargeLocalTableDoesNotExceedPeerDefault(): void
    {
        $client = new StateMachine(true, [Setting::HeaderTableSize->value => 65_536]);
        $server = new StateMachine(false);

        foreach (['first', 'second', 'first'] as $index => $value) {
            $headers = [...self::request(), new Header('x-value', str_repeat($value, 400))];
            [$frame] = Frame\decode($client->sendHeadersEncoded(($index * 2) + 1, $headers, true));
            [, $events] = $server->receive($frame);
            static::assertInstanceOf(HeadersReceived::class, $events[0]);
            static::assertEquals($headers, $events[0]->headers);
        }
    }

    #[DataProvider('provideSmallLimits')]
    public function testSmallerInitialEncoderTableSendsSizeUpdate(int $limit): void
    {
        $client = new StateMachine(true, [Setting::HeaderTableSize->value => $limit]);
        [$frame] = Frame\decode($client->sendHeadersEncoded(1, self::request(), true));
        $block = HeadersFrame::fromRaw($frame)->headerBlockFragment;
        [$size] = IntegerCodec::decode($block, 0, 5);

        static::assertSame($limit, $size);
        [, $events] = new StateMachine(false)->receive($frame);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
    }

    public function testSmallerInitialDecoderLimitWaitsForAck(): void
    {
        $server = new StateMachine(false, [Setting::HeaderTableSize->value => 0]);
        $server->initialize();
        $encoder = new Encoder();
        $headers = [...self::request(), new Header('x-value', 'retained before ack')];

        self::receiveHeaders($server, $encoder, 1, $headers);
        self::receiveHeaders($server, $encoder, 3, $headers);
        self::ack($server);
        self::assertTableSizeRejected($server, 5, 1);
    }

    public function testInitialLargerDecoderLimitAllowsPeerSizeUpdate(): void
    {
        $server = new StateMachine(false, [Setting::HeaderTableSize->value => 65_536]);
        $server->initialize();
        $encoder = new Encoder();
        $encoder->resize(65_536);

        self::receiveHeaders($server, $encoder, 1, self::request());
        self::ack($server);
        self::receiveHeaders($server, $encoder, 3, self::request());
    }

    public function testAdvertisedDecoderIncreaseWorksBeforeAck(): void
    {
        $server = new StateMachine(false);
        $server->initialize();
        self::ack($server);
        $server->sendSettings([Setting::HeaderTableSize->value => 8192]);
        $encoder = new Encoder();
        $encoder->resize(8192);

        self::receiveHeaders($server, $encoder, 1, self::request());
        self::ack($server);
        self::receiveHeaders($server, $encoder, 3, self::request());
    }

    public function testDecoderReductionWaitsForItsAck(): void
    {
        $server = new StateMachine(false);
        $server->initialize();
        self::ack($server);
        $server->sendSettings([Setting::HeaderTableSize->value => 0]);
        $server->receive(new SettingsFrame([], false)->toRaw());
        $encoder = new Encoder();
        $headers = [...self::request(), new Header('x-value', 'retained before ack')];

        self::receiveHeaders($server, $encoder, 1, $headers);
        self::receiveHeaders($server, $encoder, 3, $headers);
        self::ack($server);
        self::assertTableSizeRejected($server, 5, 1);
    }

    public function testEverySettingsFrameConsumesOneDecoderLimitAck(): void
    {
        $server = new StateMachine(false, [Setting::HeaderTableSize->value => 8192]);
        $server->initialize();
        $server->sendSettings([]);
        $server->sendSettings([Setting::HeaderTableSize->value => 256]);
        $server->sendSettings([Setting::HeaderTableSize->value => 0]);
        $encoder = new Encoder();
        $encoder->resize(8192);

        self::ack($server);
        self::receiveHeaders($server, $encoder, 1, self::request());
        self::ack($server);
        $encoder->resize(8192);
        self::receiveHeaders($server, $encoder, 3, self::request());
        self::ack($server);
        self::assertTableSizeRejected($server, 5, 257);
        self::ack($server);
        self::assertTableSizeRejected($server, 7, 1);
    }

    public function testEarlierAckPreservesPendingDecoderIncrease(): void
    {
        $server = new StateMachine(false);
        $server->initialize();
        $server->sendSettings([Setting::HeaderTableSize->value => 8192]);
        $server->sendSettings([Setting::HeaderTableSize->value => 0]);
        $encoder = new Encoder();
        $encoder->resize(8192);

        self::ack($server);
        self::receiveHeaders($server, $encoder, 1, self::request());
        self::ack($server);
        $encoder->resize(8192);
        self::receiveHeaders($server, $encoder, 3, self::request());
        self::ack($server);
        self::assertTableSizeRejected($server, 5, 1);
    }

    /** @return iterable<string, array{int}> */
    public static function provideSmallLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'small table' => [64];
    }

    /** @return list<Header> */
    private static function request(): array
    {
        return [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':authority', 'example.com'),
            new Header(':path', '/'),
        ];
    }

    /** @param list<Header> $headers */
    private static function receiveHeaders(StateMachine $server, Encoder $encoder, int $streamId, array $headers): void
    {
        [, $events] = $server->receive(new HeadersFrame($streamId, $encoder->encode($headers), true, true)->toRaw());
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertEquals($headers, $events[0]->headers);
    }

    private static function ack(StateMachine $server): void
    {
        $server->receive(new SettingsFrame([], true)->toRaw());
    }

    private static function assertTableSizeRejected(StateMachine $server, int $streamId, int $size): void
    {
        $encoder = new Encoder();
        $encoder->resize($size);

        try {
            $server->receive(new HeadersFrame($streamId, $encoder->encode(self::request()), true, true)->toRaw());
            static::fail('Expected the decoder to reject a table size above its limit.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::CompressionError, $error->errorCode);
        }
    }
}
