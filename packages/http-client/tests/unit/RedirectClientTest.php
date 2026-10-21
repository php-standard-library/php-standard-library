<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\HTTP\Client\ClientInterface;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Client\Exception\TooManyRedirectsException;
use Psl\HTTP\Client\RedirectClient;
use Psl\HTTP\Client\SendConfiguration;
use Psl\HTTP\Message\FieldMap;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Response;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\URL;

use function array_values;
use function str_repeat;

final class RedirectClientTest extends TestCase
{
    private static function fakeClient(Response ...$responses): ClientInterface
    {
        return new class(array_values($responses)) implements ClientInterface {
            /** @var non-negative-int */
            private int $index = 0;

            /**
             * @param list<Response> $responses
             */
            public function __construct(
                private readonly array $responses,
            ) {}

            /** @var list<Request> */
            public array $requests = [];

            /** @var list<null|string> */
            public array $bodies = [];

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $this->requests[] = $request;
                $this->bodies[] = $request->body?->readAll(cancellation: $cancellation);

                return new Transaction([], null, $this->responses[$this->index++]);
            }
        };
    }

    /** @param int<100, 999> $status */
    private static function redirect(int $status, string $location): Response
    {
        return new Response(status: $status, headers: FieldMap::from([['location', $location]]));
    }

    private static function ok(): Response
    {
        return new Response(status: 200, headers: FieldMap::default(), body: new IO\MemoryHandle('ok'));
    }

    /** @param non-empty-uppercase-string $method */
    private static function request(
        string $method = 'GET',
        string $url = 'http://example.com/',
        null|FieldMap $headers = null,
    ): Request {
        return new Request(method: $method, url: URL\parse($url), headers: $headers ?? FieldMap::default());
    }

    public function testNoRedirect(): void
    {
        $inner = self::fakeClient(self::ok());
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testFollows301(): void
    {
        $inner = self::fakeClient(self::redirect(301, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testFollows302(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testFollows303(): void
    {
        $inner = self::fakeClient(self::redirect(303, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testFollows307(): void
    {
        $inner = self::fakeClient(self::redirect(307, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testFollows308(): void
    {
        $inner = self::fakeClient(self::redirect(308, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testDoesNotFollow300(): void
    {
        $inner = self::fakeClient(self::redirect(300, 'http://example.com/new'));
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(300, $tx->response->status);
    }

    public function testDoesNotFollow304(): void
    {
        $inner = self::fakeClient(self::redirect(304, 'http://example.com/new'));
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(304, $tx->response->status);
    }

    public function testDoesNotFollow305(): void
    {
        $inner = self::fakeClient(self::redirect(305, 'http://example.com/proxy'));
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(305, $tx->response->status);
    }

    public function testDoesNotFollow4xx(): void
    {
        $response = new Response(status: 404, headers: FieldMap::from([['location', 'http://example.com/']]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(404, $tx->response->status);
    }

    public function test301RewritesPostToGet(): void
    {
        $inner = self::fakeClient(self::redirect(301, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('POST'));

        static::assertSame('GET', $inner->requests[1]->method);
        static::assertNull($inner->requests[1]->body);
    }

    public function test302RewritesPostToGet(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('POST'));

        static::assertSame('GET', $inner->requests[1]->method);
    }

    /** @return iterable<string, array{301|302, non-empty-uppercase-string}> */
    public static function methodPreservingRedirects(): iterable
    {
        foreach ([301, 302] as $status) {
            foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'CUSTOM'] as $method) {
                yield $status . ' ' . $method => [$status, $method];
            }
        }
    }

    /**
     * @param 301|302 $status
     * @param non-empty-uppercase-string $method
     */
    #[DataProvider('methodPreservingRedirects')]
    public function test301And302PreserveOtherMethodsAndBodies(int $status, string $method): void
    {
        $inner = self::fakeClient(self::redirect($status, '/new'), self::ok());
        $body = new IO\MemoryHandle('prefix:payload');
        $body->seek(7);
        $client = new RedirectClient($inner);
        $request = self::request($method, headers: FieldMap::from([
            ['content-length', '7'],
            ['content-type',   'text/plain'],
        ]))
            ->withBody($body);

        $client->send($request);

        static::assertSame($method, $inner->requests[1]->method);
        static::assertSame($body, $inner->requests[1]->body);
        static::assertSame('7', $inner->requests[1]->headers->get('content-length'));
        static::assertSame('text/plain', $inner->requests[1]->headers->get('content-type'));
        static::assertSame(['payload', 'payload'], $inner->bodies);
    }

    public function test303RewritesToGet(): void
    {
        $inner = self::fakeClient(self::redirect(303, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('PUT'));

        static::assertSame('GET', $inner->requests[1]->method);
    }

    public function testGetNotRewrittenOn301(): void
    {
        $inner = self::fakeClient(self::redirect(301, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('GET'));

        static::assertSame('GET', $inner->requests[1]->method);
    }

    public function testHeadNotRewrittenOn303(): void
    {
        $inner = self::fakeClient(self::redirect(303, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('HEAD'));

        static::assertSame('HEAD', $inner->requests[1]->method);
    }

    public function test307PreservesMethod(): void
    {
        $inner = self::fakeClient(self::redirect(307, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('POST'));

        static::assertSame('POST', $inner->requests[1]->method);
    }

    public function test308PreservesMethod(): void
    {
        $inner = self::fakeClient(self::redirect(308, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('PUT'));

        static::assertSame('PUT', $inner->requests[1]->method);
    }

    /** @return iterable<string, array{307|308}> */
    public static function bodyPreservingRedirects(): iterable
    {
        yield 'temporary' => [307];
        yield 'permanent' => [308];
    }

    #[DataProvider('bodyPreservingRedirects')]
    public function testRedirectRewindsConsumedBody(int $status): void
    {
        $inner = self::fakeClient(self::redirect($status, '/new'), self::redirect($status, '/last'), self::ok());
        $body = new IO\MemoryHandle('prefix:payload');
        $body->seek(7);
        $client = new RedirectClient($inner);

        $client->send(self::request('POST')->withBody($body));

        static::assertSame(['payload', 'payload', 'payload'], $inner->bodies);
    }

    #[DataProvider('bodyPreservingRedirects')]
    public function testRedirectRejectsConsumedNonSeekableBody(int $status): void
    {
        $inner = self::fakeClient(self::redirect($status, '/new'), self::ok());
        $body = new IO\Reader(new IO\MemoryHandle('payload'));
        $client = new RedirectClient($inner);

        try {
            $client->send(self::request('POST')->withBody($body));
            static::fail('Expected a request exception for a body that cannot be rewound.');
        } catch (RequestException $exception) {
            static::assertStringContainsString(
                'Cannot redirect a request with a non-seekable body',
                $exception->getMessage(),
            );
        }

        static::assertSame(['payload'], $inner->bodies);
    }

    #[DataProvider('bodyPreservingRedirects')]
    public function testRedirectResendsUnreadNonSeekableBody(int $status): void
    {
        // The first hop ignores the body (as Expect: 100-continue does when a
        // final response pre-empts the upload); the final hop reads it.
        $inner = new class([self::redirect($status, '/last'), self::ok()]) implements ClientInterface {
            /** @var non-negative-int */
            private int $index = 0;

            /** @var list<null|string> */
            public array $bodies = [];

            /**
             * @param list<Response> $responses
             */
            public function __construct(
                private readonly array $responses,
            ) {}

            #[Override]
            public function send(
                Request $request,
                SendConfiguration $configuration = new SendConfiguration(),
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): Transaction {
                $response = $this->responses[$this->index++];
                // Read the body only once a non-redirect response is returned.
                $this->bodies[] =
                    $response->status >= 300 && $response->status < 400
                        ? null
                        : $request->body?->readAll(cancellation: $cancellation);

                return new Transaction([], null, $response);
            }
        };
        $body = new IO\Reader(new IO\MemoryHandle('payload'));
        $client = new RedirectClient($inner);

        $client->send(self::request('POST')->withBody($body));

        static::assertSame([null, 'payload'], $inner->bodies);
    }

    public function testRedirectWrapsBodyPositionFailure(): void
    {
        $inner = self::fakeClient(self::redirect(307, '/new'), self::ok());
        $body = new class implements IO\ReadHandleInterface, IO\SeekHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            public function reachedEndOfDataSource(): bool
            {
                return false;
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                return '';
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return '';
            }

            public function seek(int $offset): void {}

            public function tell(): int
            {
                throw new IO\Exception\RuntimeException('Cannot tell.');
            }
        };
        $client = new RedirectClient($inner);

        try {
            $client->send(self::request('POST')->withBody($body));
            static::fail('Expected the body position failure to surface as a request exception.');
        } catch (RequestException $exception) {
            static::assertStringContainsString('Cannot read the request body position', $exception->getMessage());
        }
    }

    public function testBodyHeadersStrippedOnMethodRewrite(): void
    {
        $inner = self::fakeClient(self::redirect(301, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $request = self::request('POST', 'http://example.com/', FieldMap::from([
            ['content-type',      'application/json'],
            ['content-length',    '42'],
            ['transfer-encoding', 'chunked'],
            ['accept',            'text/html'],
        ]));
        $client->send($request);

        $redirected = $inner->requests[1];
        static::assertNull($redirected->headers->get('content-type'));
        static::assertNull($redirected->headers->get('content-length'));
        static::assertNull($redirected->headers->get('transfer-encoding'));
        static::assertSame('text/html', $redirected->headers->get('accept'));
    }

    public function testSensitiveHeadersStrippedOnCrossOriginRedirect(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://other.com/'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization',       'Bearer secret'],
            ['cookie',              'session=abc'],
            ['proxy-authorization', 'Basic xyz'],
            ['accept',              'text/html'],
        ]));
        $client->send($request);

        $redirected = $inner->requests[1];
        static::assertNull($redirected->headers->get('authorization'));
        static::assertNull($redirected->headers->get('cookie'));
        static::assertNull($redirected->headers->get('proxy-authorization'));
        static::assertSame('text/html', $redirected->headers->get('accept'));
    }

    public function testSensitiveHeadersKeptOnSameOriginRedirect(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/other'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
            ['cookie',        'session=abc'],
        ]));
        $client->send($request);

        $redirected = $inner->requests[1];
        static::assertSame('Bearer secret', $redirected->headers->get('authorization'));
        static::assertSame('session=abc', $redirected->headers->get('cookie'));
    }

    public function testSensitiveHeadersStrippedOnSchemeChange(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'https://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testSensitiveHeadersStrippedOnPortChange(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com:9090/'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testSameOriginWithDefaultPort(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com:80/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertSame('Bearer secret', $inner->requests[1]->headers->get('authorization'));
    }

    public function testRefererSetOnRedirect(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: true);
        $client->send(self::request('GET', 'http://example.com/old?q=1'));

        static::assertSame('http://example.com/old?q=1', $inner->requests[1]->headers->get('referer'));
    }

    public function testRefererStrippedWhenHttpsToHttp(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: true);
        $client->send(self::request('GET', 'https://example.com/secure'));

        static::assertNull($inner->requests[1]->headers->get('referer'));
    }

    public function testRefererSetWhenHttpToHttps(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'https://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: true);
        $client->send(self::request('GET', 'http://example.com/old'));

        static::assertSame('http://example.com/old', $inner->requests[1]->headers->get('referer'));
    }

    public function testRefererSetWhenHttpsToHttps(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'https://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: true);
        $client->send(self::request('GET', 'https://example.com/old'));

        static::assertSame('https://example.com/old', $inner->requests[1]->headers->get('referer'));
    }

    public function testRefererNotSetWhenDisabled(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $client->send(self::request('GET', 'http://example.com/old'));

        static::assertNull($inner->requests[1]->headers->get('referer'));
    }

    public function testAbsolutePathRedirect(): void
    {
        $inner = self::fakeClient(self::redirect(302, '/new-path'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $client->send(self::request('GET', 'http://example.com/old'));

        $url = $inner->requests[1]->url;
        static::assertNotNull($url);
        static::assertSame('http', $url->scheme);
        static::assertSame('example.com', $url->authority->host->toString());
        static::assertSame('/new-path', $url->path);
    }

    public function testAbsolutePathWithQuery(): void
    {
        $inner = self::fakeClient(self::redirect(302, '/search?q=test'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $client->send(self::request('GET', 'http://example.com/old'));

        $url = $inner->requests[1]->url;
        static::assertNotNull($url);
        static::assertSame('/search', $url->path);
        static::assertSame('q=test', $url->query);
    }

    public function testRelativePathRedirect(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'other-page'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $client->send(self::request('GET', 'http://example.com/dir/old'));

        $url = $inner->requests[1]->url;
        static::assertNotNull($url);
        static::assertSame('/dir/other-page', $url->path);
    }

    public function testDotSegmentRemoval(): void
    {
        $inner = self::fakeClient(self::redirect(302, '/a/b/../c/./d'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $client->send(self::request('GET', 'http://example.com/'));

        $url = $inner->requests[1]->url;
        static::assertNotNull($url);
        static::assertSame('/a/c/d', $url->path);
    }

    public function testRelativePathWithDotSegments(): void
    {
        $inner = self::fakeClient(self::redirect(302, '../other'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $client->send(self::request('GET', 'http://example.com/a/b/page'));

        $url = $inner->requests[1]->url;
        static::assertNotNull($url);
        static::assertSame('/a/other', $url->path);
    }

    public function testNoLocationHeaderStopsRedirect(): void
    {
        $noLocation = new Response(status: 302, headers: FieldMap::default());
        $inner = self::fakeClient($noLocation);
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(302, $tx->response->status);
    }

    public function testMultipleLocationHeadersNotFollowed(): void
    {
        $response = new Response(status: 302, headers: FieldMap::from([
            ['location', 'http://example.com/a'],
            ['location', 'http://example.com/b'],
        ]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(302, $tx->response->status);
    }

    public function testTooManyRedirectsThrows(): void
    {
        $responses = [];
        for ($i = 0; $i < 15; $i++) {
            $responses[] = self::redirect(302, 'http://example.com/loop');
        }

        $inner = self::fakeClient(...$responses);
        $client = new RedirectClient($inner, maxRedirects: 5);

        $this->expectException(TooManyRedirectsException::class);
        $client->send(self::request());
    }

    public function testMultipleRedirects(): void
    {
        $inner = self::fakeClient(
            self::redirect(302, 'http://example.com/a'),
            self::redirect(302, 'http://example.com/b'),
            self::redirect(302, 'http://example.com/c'),
            self::ok(),
        );

        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
        static::assertCount(4, $inner->requests);
    }

    public function testInvalidLocationThrowsProtocolException(): void
    {
        $response = new Response(status: 302, headers: FieldMap::from([['location', 'ht tp://bad url']]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);

        $this->expectException(ProtocolException::class);
        $client->send(self::request());
    }

    public function testRedirectDiscardsResponseBody(): void
    {
        $body = new IO\MemoryHandle('redirect body content');
        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $client->send(self::request());

        static::assertTrue($body->reachedEndOfDataSource());
    }

    public function testDefaultMaxRedirectsIsExactly10(): void
    {
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = self::redirect(302, 'http://example.com/r' . $i);
        }

        $responses[] = self::ok();

        $inner = self::fakeClient(...$responses);
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testDefaultMaxRedirectsExceededAt11(): void
    {
        $responses = [];
        for ($i = 0; $i < 11; $i++) {
            $responses[] = self::redirect(302, 'http://example.com/r' . $i);
        }

        $responses[] = self::ok();

        $inner = self::fakeClient(...$responses);
        $client = new RedirectClient($inner);

        $this->expectException(TooManyRedirectsException::class);
        $client->send(self::request());
    }

    public function testDefaultAutoReferrerIsTrue(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner);
        $client->send(self::request('GET', 'http://example.com/old'));

        static::assertNotNull($inner->requests[1]->headers->get('referer'));
        static::assertSame('http://example.com/old', $inner->requests[1]->headers->get('referer'));
    }

    public function testEmptyLocationReturnsTransaction(): void
    {
        $response = new Response(status: 302, headers: FieldMap::from([['location', '']]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);
        $tx = $client->send(self::request());

        static::assertSame(302, $tx->response->status);
    }

    public function testMaxRedirectsBoundary(): void
    {
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = self::redirect(302, 'http://example.com/r' . $i);
        }

        $responses[] = self::ok();

        $inner = self::fakeClient(...$responses);
        $client = new RedirectClient($inner, maxRedirects: 5);
        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testInvalidLocationErrorMessageFormat(): void
    {
        $invalidUrl = 'ht tp://bad url';
        $response = new Response(status: 302, headers: FieldMap::from([['location', $invalidUrl]]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid redirect URL: ' . $invalidUrl);

        $client->send(self::request());
    }

    public function testSameOriginWithMixedCaseScheme(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'HTTP://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertSame('Bearer secret', $inner->requests[1]->headers->get('authorization'));
    }

    public function testSameOriginWithMixedCaseHost(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://EXAMPLE.COM/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertSame('Bearer secret', $inner->requests[1]->headers->get('authorization'));
    }

    public function testCrossOriginDifferentSchemeStripsCredentials(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'https://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testSameOriginWithExplicitDefaultPort(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com:80/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertSame('Bearer secret', $inner->requests[1]->headers->get('authorization'));
    }

    public function testCrossOriginWithDifferentPort(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com:8080/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer secret'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testInvalidLocationErrorMessageContainsPrefixBeforeUrl(): void
    {
        $invalidUrl = 'ht tp://bad url';
        $response = new Response(status: 302, headers: FieldMap::from([['location', $invalidUrl]]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Invalid redirect URL: ' . $invalidUrl);

        $client->send(self::request());
    }

    public function testInvalidLocationErrorMessageEndsWithUrl(): void
    {
        $invalidUrl = 'ht tp://bad url';
        $response = new Response(status: 302, headers: FieldMap::from([['location', $invalidUrl]]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage($invalidUrl);

        $client->send(self::request());
    }

    public function testInvalidLocationErrorMessageExactFormat(): void
    {
        $invalidUrl = 'ht tp://bad url';
        $response = new Response(status: 302, headers: FieldMap::from([['location', $invalidUrl]]));
        $inner = self::fakeClient($response);
        $client = new RedirectClient($inner);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Malformed HTTP response: Invalid redirect URL: ' . $invalidUrl);

        $client->send(self::request());
    }

    public function testIsSameOriginCaseInsensitiveSchemeFromLeft(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'HTTP://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginCaseInsensitiveSchemeFromRight(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'HTTP://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginReturnsFalseOnDifferentScheme(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'https://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginCaseInsensitiveHostFromLeft(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://EXAMPLE.COM/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginCaseInsensitiveHostFromRight(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://EXAMPLE.COM/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginDifferentHostStripsCredentials(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://other.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginPortCoalesceUsesExplicitPortFirst(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com:80/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginExplicitPortOverridesDefault(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'http://example.com:8080/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/path', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertNull($inner->requests[1]->headers->get('authorization'));
    }

    public function testIsSameOriginBothExplicitDefaultPorts(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'https://example.com:443/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'https://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
    }

    public function testDiscardBodyDrainsFullContent(): void
    {
        $content = str_repeat('x', 20_000);
        $body = new IO\MemoryHandle($content);
        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $client->send(self::request());

        static::assertTrue($body->reachedEndOfDataSource());
    }

    public function testDiscardBodyHandlesSmallBodies(): void
    {
        $body = new IO\MemoryHandle('tiny');
        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $client->send(self::request());

        static::assertTrue($body->reachedEndOfDataSource());
    }

    public function testDiscardBodyHandlesEmptyBody(): void
    {
        $body = new IO\MemoryHandle('');
        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $tx = $client->send(self::request());

        static::assertSame(200, $tx->response->status);
    }

    public function testIsSameOriginMixedCaseBothSchemeAndHost(): void
    {
        $inner = self::fakeClient(self::redirect(302, 'HTTP://EXAMPLE.COM/new'), self::ok());
        $client = new RedirectClient($inner, autoReferrer: false);
        $request = self::request('GET', 'http://example.com/', FieldMap::from([
            ['authorization', 'Bearer token'],
            ['cookie',        'session=abc'],
        ]));
        $client->send($request);

        static::assertSame('Bearer token', $inner->requests[1]->headers->get('authorization'));
        static::assertSame('session=abc', $inner->requests[1]->headers->get('cookie'));
    }

    public function testDiscardBodyExitsOnEmptyRead(): void
    {
        $body = new class() implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            private int $state = 0;
            public int $readCount = 0;

            public function tryRead(null|int $maxBytes = null): string
            {
                return $this->read($maxBytes);
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                $this->readCount++;
                if ($this->state === 0) {
                    $this->state = 1;
                    return 'chunk1';
                }

                if ($this->state === 1) {
                    $this->state = 2;
                    return '';
                }

                $this->state = 3;
                return 'chunk2';
            }

            public function reachedEndOfDataSource(): bool
            {
                return $this->state >= 3;
            }
        };

        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $client->send(self::request());

        static::assertSame(2, $body->readCount);
    }

    public function testDiscardBodyStopsAtEmptyChunkNotEof(): void
    {
        $body = new class() implements IO\ReadHandleInterface {
            use IO\ReadHandleConvenienceMethodsTrait;

            private int $callCount = 0;
            /** @var list<string> */
            private array $data = ['data1', '', 'data2'];
            /** @var list<string> */
            public array $reads = [];

            public function tryRead(null|int $maxBytes = null): string
            {
                return $this->read($maxBytes);
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                $result = $this->data[$this->callCount] ?? '';
                $this->reads[] = $result;
                $this->callCount++;
                return $result;
            }

            public function reachedEndOfDataSource(): bool
            {
                return $this->callCount >= 3;
            }
        };

        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $client->send(self::request());

        static::assertSame(['data1', ''], $body->reads);
    }

    public function testDiscardBodyHandlesExactlyChunkSizedContent(): void
    {
        $content = str_repeat('a', 8192);
        $body = new IO\MemoryHandle($content);
        $redirectResponse = new Response(
            status: 302,
            headers: FieldMap::from([['location', 'http://example.com/new']]),
            body: $body,
        );
        $inner = self::fakeClient($redirectResponse, self::ok());
        $client = new RedirectClient($inner);

        $client->send(self::request());

        static::assertTrue($body->reachedEndOfDataSource());
    }
}
