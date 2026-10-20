<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Frame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\H2\StreamState;
use Psl\HPACK\Header;

final class ReservedStreamCompressionTest extends TestCase
{
    #[DataProvider('headerSendMethods')]
    public function testBlockedPushResponseDoesNotChangeCompressionState(bool $responseMethod): void
    {
        $client = new StateMachine(true, [Setting::MaxConcurrentStreams->value => 1]);
        $server = new StateMachine(false);
        $server->receive(new SettingsFrame([Setting::MaxConcurrentStreams->value => 1], false)->toRaw());
        $request = [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ];
        [$frame] = Frame\decode($client->sendHeadersEncoded(1, $request, true));
        $server->receive($frame);
        foreach ([2, 4] as $streamId) {
            foreach ($server->sendPushPromise(1, $streamId, $request) as $promise) {
                $client->receive($promise);
            }
        }

        [$frame] = Frame\decode($server->sendHeadersEncoded(2, [new Header(':status', '200')]));
        $client->receive($frame);
        $headers = [new Header('x-unsent', 'value')];
        $send = static fn(): string => (
            $responseMethod
                ? $server->sendResponseHeadersEncoded(4, '200', $headers)
                : $server->sendHeadersEncoded(4, [new Header(':status', '200'), ...$headers])
        );
        try {
            $send();
            static::fail('Expected the push stream limit to reject the response.');
        } catch (FlowControlException) {
            static::assertSame(StreamState::ReservedLocal, $server->getStreamState(4));
        }

        foreach ($server->sendData(2, '', true) as $frame) {
            $client->receive($frame);
        }

        [$frame] = Frame\decode($send());
        [, $events] = $client->receive($frame);
        static::assertInstanceOf(HeadersReceived::class, $events[0]);
        static::assertSame(4, $events[0]->streamId);
        static::assertEquals([new Header(':status', '200'), ...$headers], $events[0]->headers);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function headerSendMethods(): iterable
    {
        yield 'sendHeadersEncoded' => [false];
        yield 'sendResponseHeadersEncoded' => [true];
    }
}
