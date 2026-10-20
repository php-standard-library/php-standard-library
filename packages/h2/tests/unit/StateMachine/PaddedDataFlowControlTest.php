<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Event\DataReceived;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Frame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\Internal\BDPEstimator;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function strlen;

final class PaddedDataFlowControlTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function payloads(): iterable
    {
        yield 'data' => ['abc', false];
        yield 'padding only' => ['', false];
        yield 'data with BDP' => ['abc', true];
        yield 'padding only with BDP' => ['', true];
    }

    #[DataProvider('payloads')]
    public function testPaddingIsRestoredToBothWindows(string $data, bool $useBdp): void
    {
        $sm = new StateMachine(false, bdpEstimator: $useBdp ? new BDPEstimator(4, 1_048_576) : null);
        $sm->receive(
            new HeadersFrame(
                1,
                new Encoder()->encode([
                    new Header(':method', 'POST'),
                    new Header(':scheme', 'https'),
                    new Header(':path', '/'),
                    new Header('content-length', (string) strlen($data)),
                ]),
                false,
                true,
            )->toRaw(),
        );

        $payload = "\x03" . $data . "\0\0\0";
        [$responses, $events] = $sm->receive(new RawFrame(0, 0x09, 1, $payload));
        static::assertInstanceOf(DataReceived::class, $events[0]);
        static::assertSame($data, $events[0]->data);

        $wire = '';
        foreach ($responses as $response) {
            $wire .= $response instanceof RawFrame ? Frame\encode($response) : $response;
        }

        static::assertSame(26, strlen($wire));
        foreach ([0 => 0, 13 => 1] as $offset => $streamId) {
            [$raw] = Frame\decode($wire, $offset);
            $update = WindowUpdateFrame::fromRaw($raw);
            static::assertSame($streamId, $update->streamId);
            static::assertSame(strlen($payload), $update->windowSizeIncrement);
        }
    }

    public function testPaddingCannotExceedReceiveWindow(): void
    {
        $sm = new StateMachine(false, [Setting::InitialWindowSize->value => 3]);
        $sm->receive(
            new HeadersFrame(
                1,
                new Encoder()->encode([
                    new Header(':method', 'POST'),
                    new Header(':scheme', 'https'),
                    new Header(':path', '/'),
                ]),
                false,
                true,
            )->toRaw(),
        );

        $this->expectException(FlowControlException::class);
        $sm->receive(new RawFrame(0, 0x08, 1, "\x03\0\0\0"));
    }
}
