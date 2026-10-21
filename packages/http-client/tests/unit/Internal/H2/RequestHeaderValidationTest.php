<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\Async\NullCancellationToken;
use Psl\H2;
use Psl\HPACK;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Client\Internal\H2\StreamExchange;
use Psl\HTTP\Client\Tests\Fixture\H2\ChunkedHandle;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\URL;

use function str_repeat;
use function strlen;

final class RequestHeaderValidationTest extends TestCase
{
    public function testFiltersConnectionFieldsInHeadersAndTrailers(): void
    {
        $handle = self::responseHandle(1);
        $session = new H2Session($handle, new H2ClientConfiguration());
        $url = URL\parse('https://example.com/');
        $fields = FieldMap::from([
            ['Host',              'example.com'],
            ['Connection',        'X-Hop, Keep-Alive'],
            ['Connection',        ' x-SECOND '],
            ['Keep-Alive',        'timeout=5'],
            ['Proxy-Connection',  'keep-alive'],
            ['Transfer-Encoding', 'chunked'],
            ['Upgrade',           'h2c'],
            ['X-Hop',             'removed'],
            ['X-Second',          'removed'],
            ['TE',                'gzip'],
            ['TE',                'TraIlErS'],
            ['X-End-To-End',      'retained'],
        ]);
        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            headers: $fields,
            body: new IO\MemoryHandle('body'),
            trailers: Async\Awaitable::complete($fields->with('Connection', 'x-trailer')->with('X-Trailer', 'removed')),
        );

        $transaction = StreamExchange::exchange(
            $session,
            $request,
            $url,
            new ClientConfiguration(),
            new NullCancellationToken(),
        );
        static::assertSame(204, $transaction->response->status);

        $decoder = new HPACK\Decoder();
        $blocks = [];
        $offset = 24;
        while ($offset < strlen($handle->written)) {
            [$frame, $offset] = H2\Frame\decode($handle->written, $offset);
            if ($frame->type !== H2\Frame\FrameType::Headers->value) {
                continue;
            }

            $fields = [];
            foreach ($decoder->decode($frame->payload) as $header) {
                $fields[$header->name] = $header->value;
            }

            $blocks[] = $fields;
        }

        static::assertSame(
            [
                ':method' => 'POST',
                ':path' => '/',
                ':scheme' => 'https',
                ':authority' => 'example.com',
                'te' => 'TraIlErS',
                'x-end-to-end' => 'retained',
            ],
            $blocks[0],
        );
        static::assertSame(['te' => 'TraIlErS', 'x-end-to-end' => 'retained'], $blocks[1]);
        static::assertFalse($session->isClosed());
    }

    #[DataProvider('invalidValues')]
    public function testInvalidLocalFieldLeavesSessionUsable(string $value): void
    {
        $handle = self::responseHandle(3);
        $session = new H2Session($handle, new H2ClientConfiguration());
        $before = $handle->written;
        $url = URL\parse('https://example.com/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/', headers: FieldMap::from([[
            'x-invalid',
            $value,
        ]]));

        try {
            StreamExchange::exchange($session, $request, $url, new ClientConfiguration(), new NullCancellationToken());
            static::fail('Expected local field validation to reject the request.');
        } catch (H2\Exception\StreamException) {
            static::assertSame($before, $handle->written);
            static::assertFalse($session->isClosed());
        }

        $transaction = StreamExchange::exchange(
            $session,
            new Request(method: 'GET', url: $url, requestTarget: '/'),
            $url,
            new ClientConfiguration(),
            new NullCancellationToken(),
        );
        static::assertSame(204, $transaction->response->status);
        static::assertFalse($session->isClosed());
    }

    public function testHeaderListSizeFailureLeavesSessionUsable(): void
    {
        $handle = self::responseHandle(3, [H2\Setting::MaxHeaderListSize->value => 250]);
        $session = new H2Session($handle, new H2ClientConfiguration(maxConcurrentStreams: 1));
        $session->connection->readEvent();
        $before = $handle->written;
        $url = URL\parse('https://example.com/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/', headers: FieldMap::from([[
            'x-oversized',
            str_repeat('x', 512),
        ]]));

        try {
            StreamExchange::exchange($session, $request, $url, new ClientConfiguration(), new NullCancellationToken());
            static::fail('Expected the peer header-list limit to reject the request.');
        } catch (H2\Exception\ProtocolException $e) {
            static::assertInstanceOf(HPACK\Exception\HeaderListSizeException::class, $e->getPrevious());
            static::assertSame($before, $handle->written);
            static::assertFalse($session->isClosed());
        }

        $transaction = StreamExchange::exchange(
            $session,
            new Request(method: 'GET', url: $url, requestTarget: '/'),
            $url,
            new ClientConfiguration(),
            new NullCancellationToken(),
        );
        static::assertSame(204, $transaction->response->status);
        static::assertFalse($session->isClosed());
    }

    public function testInvalidTrailersResetOnlyTheirStream(): void
    {
        $handle = self::responseHandle(3, [H2\Setting::MaxConcurrentStreams->value => 1]);
        $session = new H2Session($handle, new H2ClientConfiguration());
        $session->connection->readEvent();
        $url = URL\parse('https://example.com/');
        $request = new Request(
            method: 'POST',
            url: $url,
            requestTarget: '/',
            body: new IO\MemoryHandle('body'),
            trailers: Async\Awaitable::complete(FieldMap::from([['x-invalid', ' value']])),
        );

        try {
            StreamExchange::exchange($session, $request, $url, new ClientConfiguration(), new NullCancellationToken());
            static::fail('Expected local field validation to reject the trailers.');
        } catch (H2\Exception\StreamException) {
            static::assertSame(H2\StreamState::Closed, $session->connection->getStreamState(1));
            static::assertSame(0, $session->connection->activeStreamCount());
            static::assertFalse($session->isClosed());
        }

        $transaction = StreamExchange::exchange(
            $session,
            new Request(method: 'GET', url: $url, requestTarget: '/'),
            $url,
            new ClientConfiguration(),
            new NullCancellationToken(),
        );
        static::assertSame(204, $transaction->response->status);
        static::assertFalse($session->isClosed());

        $resets = [];
        $offset = 24;
        while ($offset < strlen($handle->written)) {
            [$frame, $offset] = H2\Frame\decode($handle->written, $offset);
            if ($frame->type === H2\Frame\FrameType::RstStream->value) {
                $resets[] = H2\Frame\RstStreamFrame::fromRaw($frame);
            }
        }

        static::assertCount(1, $resets);
        static::assertSame(1, $resets[0]->streamId);
        static::assertSame(H2\ErrorCode::Cancel, $resets[0]->errorCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function testRejectsNonTokenMethodBeforeSending(): void
    {
        $handle = self::responseHandle(3);
        $session = new H2Session($handle, new H2ClientConfiguration());
        $before = $handle->written;
        $url = URL\parse('https://example.com/');
        $request = new Request(method: "GE\tT", url: $url, requestTarget: '/');

        try {
            StreamExchange::exchange($session, $request, $url, new ClientConfiguration(), new NullCancellationToken());
            static::fail('Expected an invalid method to be rejected.');
        } catch (RequestException $error) {
            static::assertStringContainsString('Method must be a token', $error->getMessage());
            static::assertSame($before, $handle->written);
            static::assertFalse($session->isClosed());
        }
    }

    public function testRejectsInvalidRequestTargetBeforeSending(): void
    {
        $handle = self::responseHandle(3);
        $session = new H2Session($handle, new H2ClientConfiguration());
        $before = $handle->written;
        $url = URL\parse('https://example.com/');
        $request = new Request(method: 'GET', url: $url, requestTarget: '/a b');

        try {
            StreamExchange::exchange($session, $request, $url, new ClientConfiguration(), new NullCancellationToken());
            static::fail('Expected an invalid request target to be rejected.');
        } catch (RequestException $error) {
            static::assertStringContainsString('Request target', $error->getMessage());
            static::assertSame($before, $handle->written);
            static::assertFalse($session->isClosed());
        }
    }

    public static function invalidValues(): iterable
    {
        yield 'carriage return' => ["before\rafter"];
        yield 'line feed' => ["before\nafter"];
        yield 'NUL' => ["before\0after"];
        yield 'leading space' => [' value'];
        yield 'trailing space' => ['value '];
        yield 'leading tab' => ["\tvalue"];
        yield 'trailing tab' => ["value\t"];
        yield 'vertical tab' => ["before\x0bafter"];
        yield 'bell control' => ["before\x07after"];
        yield 'delete control' => ["before\x7fafter"];
    }

    /**
     * @param positive-int $streamId
     * @param array<positive-int, non-negative-int> $settings
     */
    private static function responseHandle(int $streamId, array $settings = []): ChunkedHandle
    {
        $encoder = new HPACK\Encoder();

        return new ChunkedHandle(
            H2\Frame\encode(new H2\Frame\SettingsFrame($settings, false)->toRaw())
                . H2\Frame\encode(
                    new H2\Frame\HeadersFrame(
                        $streamId,
                        $encoder->encode([
                            new HPACK\Header(':status', '204'),
                        ]),
                        true,
                        true,
                    )->toRaw(),
                ),
        );
    }
}
