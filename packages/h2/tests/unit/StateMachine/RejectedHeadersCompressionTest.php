<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\RuntimeException;
use Psl\H2\Frame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Internal\StateMachine;
use Psl\H2\Setting;
use Psl\HPACK\Decoder;
use Psl\HPACK\Header;

final class RejectedHeadersCompressionTest extends TestCase
{
    #[DataProvider('failures')]
    public function testRejectedHeadersLeaveCompressionStateUntouched(string $reason, bool $response): void
    {
        $state = new StateMachine(true);
        $decoder = new Decoder();
        $headers = [new Header(':method', 'GET'), new Header(':scheme', 'https'), new Header(':path', '/')];
        [$first] = Frame\decode($state->sendHeadersEncoded(1, $headers));
        $decoder->decode($first->payload);
        $rejectedStream = 3;
        if ($reason === 'capacity') {
            $state->receive(new SettingsFrame([Setting::MaxConcurrentStreams->value => 1], false)->toRaw());
        } elseif ($reason === 'goaway') {
            $state->goAway(ErrorCode::NoError);
        } elseif ($reason === 'ended') {
            $state->sendData(1, '', true);
            $rejectedStream = 1;
        } else {
            $rejectedStream = 0;
        }

        $fields = [new Header('x-unsent', 'only-added-by-the-rejected-call')];
        try {
            if ($response) {
                $state->sendResponseHeadersEncoded($rejectedStream, '200', $fields, true);
            } else {
                $state->sendHeadersEncoded($rejectedStream, $fields, true);
            }

            static::fail('Expected the send to fail.');
        } catch (RuntimeException) {
            static::assertFalse($state->shutdown && $reason !== 'goaway');
        }

        if ($reason === 'ended') {
            $retryStream = 3;
        } else {
            $retryStream = 1;
        }

        [$frame] = Frame\decode($state->sendHeadersEncoded($retryStream, $fields, true));
        static::assertEquals($fields, $decoder->decode($frame->payload));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function failures(): iterable
    {
        foreach (['capacity', 'goaway', 'ended', 'invalid-id'] as $reason) {
            yield $reason . ' headers' => [$reason, false];
            yield $reason . ' response' => [$reason, true];
        }
    }
}
