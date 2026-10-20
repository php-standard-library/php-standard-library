<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\NullCancellationToken;
use Psl\H2;
use Psl\HPACK;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Client\Internal\H2\StreamExchange;
use Psl\HTTP\Client\Tests\Fixture\H2\ChunkedHandle;
use Psl\HTTP\Message\Request;
use Psl\URL;

final class StreamErrorIsolationTest extends TestCase
{
    public function testMalformedResponseFailsOnlyItsOwnConcurrentRequest(): void
    {
        $encoder = new HPACK\Encoder();
        $handle = new ChunkedHandle(
            H2\Frame\encode(new H2\Frame\SettingsFrame([], false)->toRaw())
                . H2\Frame\encode(
                    new H2\Frame\HeadersFrame(
                        1,
                        $encoder->encode([
                            new HPACK\Header(':method', 'GET'),
                        ]),
                        true,
                        true,
                    )->toRaw(),
                )
                . H2\Frame\encode(
                    new H2\Frame\HeadersFrame(
                        3,
                        $encoder->encode([
                            new HPACK\Header(':status', '204'),
                        ]),
                        true,
                        true,
                    )->toRaw(),
                ),
        );
        $session = new H2Session($handle, new H2ClientConfiguration());
        $url = URL\parse('https://example.com/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/');
        $configuration = new ClientConfiguration();
        $cancellation = new NullCancellationToken();

        [$error, $transaction] = Async\concurrently([
            static function () use (
                $session,
                $request,
                $url,
                $configuration,
                $cancellation,
            ): H2\Exception\StreamException {
                try {
                    StreamExchange::exchange($session, $request, $url, $configuration, $cancellation);
                    static::fail('Expected the malformed response to fail its request.');
                } catch (H2\Exception\StreamException $e) {
                    return $e;
                }
            },
            static fn() => StreamExchange::exchange($session, $request, $url, $configuration, $cancellation),
        ]);

        static::assertSame(1, $error->streamId);
        static::assertSame(204, $transaction->response->status);
        static::assertFalse($session->isClosed());
        static::assertTrue($session->connection->isConnected());
    }
}
