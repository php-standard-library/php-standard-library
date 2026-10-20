<?php

declare(strict_types=1);

namespace Psl\HTTP\Client;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\Exception\RequestException;
use Psl\HTTP\Client\Exception\TooManyRedirectsException;
use Psl\HTTP\Message;
use Psl\HTTP\Message\Request;
use Psl\HTTP\Message\Transaction;
use Psl\IO;
use Psl\URL;

use function in_array;
use function strtolower;

/**
 * Decorator that automatically follows HTTP redirects.
 *
 * Wraps an inner {@see ClientInterface} and transparently follows redirect responses
 * (3xx status codes with a Location header) up to a configurable maximum number of
 * hops. The redirect-following logic implements the behavior defined in RFC 9110
 * Section 15.4, with security measures to prevent credential leakage and respect
 * method semantics.
 *
 * ## Redirect behavior per status code
 *
 * - **301 Moved Permanently / 302 Found**: POST changes to GET and loses its body.
 *   Other methods keep their method and body, per RFC 9110 Sections 15.4.2-15.4.3.
 * - **303 See Other**: Methods other than GET and HEAD change to GET and lose their body.
 * - **307 Temporary Redirect / 308 Permanent Redirect**: The original method and body
 *   are preserved exactly.
 *
 * When a redirect preserves the body, it must be seekable so it can return to its
 * original position; otherwise a {@see RequestException} is thrown.
 *
 * Only status codes 301, 302, 303, 307, and 308 are followed. Other 3xx codes
 * (e.g., 300 Multiple Choices, 304 Not Modified) are returned to the caller as-is.
 *
 * ## Security measures
 *
 * - **Credential stripping on cross-origin redirects**: The Authorization, Cookie,
 *   and Proxy-Authorization headers are removed when the redirect target is a
 *   different origin (scheme, host, or port differs from the original request).
 * - **Body header cleanup on method rewrite**: When the method is rewritten to GET,
 *   body-related headers (Content-Length, Content-Type, Transfer-Encoding) are removed
 *   along with the body itself.
 * - **Referrer policy**: When {@see $autoReferrer} is enabled, the Referer header is
 *   set to the URL of the previous request. However, the Referer is suppressed when
 *   redirecting from HTTPS to HTTP to prevent leaking secure URLs over plaintext.
 * - **Response body draining**: Redirect response bodies are fully consumed and
 *   discarded to ensure proper connection reuse in pooled scenarios.
 *
 * ## Redirect loop protection
 *
 * A maximum redirect count (default: 10) prevents infinite redirect loops. When the
 * limit is exceeded, a {@see Exception\TooManyRedirectsException} is thrown.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-15.4 Redirections
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-15.4.2 301 Moved Permanently
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-15.4.8 307 Temporary Redirect
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-15.4.9 308 Permanent Redirect
 *
 * @api
 */
final readonly class RedirectClient implements ClientInterface
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private const array SENSITIVE_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];

    private const array BODY_HEADERS = ['content-length', 'content-type', 'transfer-encoding'];

    /**
     * Create a new redirect-following client.
     *
     * @param ClientInterface $inner The inner client to delegate requests to. This is typically a {@see Client} instance, but may be any {@see ClientInterface} implementation.
     * @param int $maxRedirects Maximum number of redirects to follow before throwing {@see Exception\TooManyRedirectsException}. Defaults to 10.
     * @param bool $autoReferrer Whether to automatically set the Referer header on redirect requests. Defaults to {@see true}. The Referer is suppressed when redirecting from HTTPS to HTTP.
     */
    public function __construct(
        private ClientInterface $inner,
        private int $maxRedirects = 10,
        private bool $autoReferrer = true,
    ) {}

    /**
     * Send an HTTP request, automatically following redirects.
     *
     * Delegates the request to the inner client and follows redirect responses
     * (301, 302, 303, 307, 308) up to the configured maximum. Returns the final
     * non-redirect transaction. If the response is not a redirect, it is returned
     * immediately.
     *
     * For each redirect hop, the following processing occurs:
     *
     * 1. The redirect response body is fully drained to allow connection reuse.
     * 2. The Location header is resolved against the current request URL.
     * 3. For 301/302 responses to POST and 303 responses to non-GET/HEAD methods,
     *    the method changes to GET and the body and body-related headers are removed.
     * 4. For cross-origin redirects, sensitive headers (Authorization, Cookie,
     *    Proxy-Authorization) are stripped.
     * 5. If {@see $autoReferrer} is enabled, the Referer header is set to the previous
     *    request URL (suppressed when redirecting from HTTPS to HTTP).
     *
     * Redirect responses with a missing, empty, or ambiguous Location header
     * (multiple values) are returned as-is without following.
     *
     * @throws Exception\TooManyRedirectsException If the number of redirects exceeds the configured maximum.
     * @throws Exception\ProtocolException If a Location header value cannot be resolved to a valid URL.
     * @throws Exception\RequestException If a redirect preserves a body that cannot be rewound.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9110#section-15.4 Redirections
     *
     * @inheritDoc
     */
    #[Override]
    public function send(
        Request $request,
        SendConfiguration $configuration = new SendConfiguration(),
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Transaction {
        $redirectCount = 0;

        while (true) {
            $bodyPosition = $request->body instanceof IO\SeekHandleInterface ? $request->body->tell() : null;
            $transaction = $this->inner->send($request, $configuration, $cancellation);
            $response = $transaction->response;

            if (!in_array($response->status, self::REDIRECT_STATUSES, strict: true)) {
                return $transaction;
            }

            $location = $response->headers->get('location');
            if ($location === null || $location === '') {
                return $transaction;
            }

            if ($response->headers->getAll('location') !== [$location]) {
                return $transaction;
            }

            $redirectCount++;
            if ($redirectCount > $this->maxRedirects) {
                throw TooManyRedirectsException::create($redirectCount);
            }

            self::discardBody($response->body, $cancellation);

            $originalUrl = $request->url;
            $targetUrl = self::resolveLocation($location, $originalUrl);
            $request = $request->withUrl($targetUrl);

            if (
                ($response->status === 301 || $response->status === 302) && $request->method === Message\METHOD_POST
                || $response->status === 303
                && $request->method !== Message\METHOD_GET
                && $request->method !== Message\METHOD_HEAD
            ) {
                $request = $request
                    ->withMethod(Message\METHOD_GET)
                    ->withBody(null)
                    ->withTrailers(null);

                foreach (self::BODY_HEADERS as $header) {
                    $request = $request->withoutHeader($header);
                }
            }

            if ($request->body !== null) {
                if (!$request->body instanceof IO\SeekHandleInterface || $bodyPosition === null) {
                    throw RequestException::forInvalidRequest('Cannot redirect a request with a non-seekable body.');
                }

                try {
                    $request->body->seek($bodyPosition);
                } catch (IO\Exception\ExceptionInterface $exception) {
                    throw new RequestException('Cannot rewind the request body for a redirect.', previous: $exception);
                }
            }

            if ($originalUrl !== null && !self::isSameOrigin($originalUrl, $targetUrl)) {
                foreach (self::SENSITIVE_HEADERS as $header) {
                    $request = $request->withoutHeader($header);
                }
            }

            if ($this->autoReferrer && $originalUrl !== null) {
                $referrerIsEncrypted = $originalUrl->scheme === 'https';
                $destinationIsEncrypted = $targetUrl->scheme === 'https';

                if (!$referrerIsEncrypted || $destinationIsEncrypted) {
                    $referer = $originalUrl->scheme . '://' . $originalUrl->authority->host->toString();
                    if ($originalUrl->authority->port !== null) {
                        $referer .= ':' . $originalUrl->authority->port;
                    }

                    $referer .= $originalUrl->path;
                    if ($originalUrl->query !== null) {
                        $referer .= '?' . $originalUrl->query;
                    }

                    $request = $request->withHeader('referer', $referer);
                } else {
                    $request = $request->withoutHeader('referer');
                }
            }
        }
    }

    /**
     * Resolve a Location header value against the current request URL.
     *
     * The Location header may be an absolute URL or a relative reference. If a
     * base URL is available (from the current request), relative references are
     * resolved against it per RFC 3986 Section 5. If no base URL is available and
     * the Location is not an absolute URL, a {@see ProtocolException} is thrown.
     *
     * @param non-empty-string $location The Location header value from the redirect response.
     *
     * @throws ProtocolException If the Location URL cannot be resolved.
     */
    private static function resolveLocation(string $location, null|URL\URL $baseUrl): URL\URL
    {
        if ($baseUrl === null) {
            try {
                return URL\parse($location);
            } catch (URL\Exception\InvalidURLException) {
                throw ProtocolException::forMalformedResponse('Cannot resolve redirect URL without a base URL: '
                . $location);
            }
        }

        try {
            return Internal\resolve_url($location, $baseUrl);
        } catch (URL\Exception\InvalidURLException) {
            throw ProtocolException::forMalformedResponse('Invalid redirect URL: ' . $location);
        }
    }

    /**
     * Determine whether two URLs share the same origin (scheme, host, and port).
     *
     * Uses case-insensitive comparison for scheme and host. When an explicit port
     * is not present in the URL, the default port for the scheme is assumed
     * (e.g., 443 for HTTPS, 80 for HTTP).
     */
    private static function isSameOrigin(URL\URL $a, URL\URL $b): bool
    {
        if (strtolower($a->scheme) !== strtolower($b->scheme)) {
            return false;
        }

        if (strtolower($a->authority->host->toString()) !== strtolower($b->authority->host->toString())) {
            return false;
        }

        $portA = $a->authority->port ?? URL\Internal\default_port($a->scheme);
        $portB = $b->authority->port ?? URL\Internal\default_port($b->scheme);

        return $portA === $portB;
    }

    /**
     * Fully consume and discard a response body to allow connection reuse.
     *
     * Redirect response bodies must be drained before the connection can be
     * returned to the pool. Read errors are silently ignored since the body
     * content is not needed.
     */
    private static function discardBody(
        null|IO\ReadHandleInterface $body,
        CancellationTokenInterface $cancellation,
    ): void {
        if ($body === null) {
            return;
        }

        try {
            while (!$body->reachedEndOfDataSource()) {
                $_chunk = $body->read(8192, $cancellation);
                if ($_chunk === '') {
                    break;
                }
            }
        } catch (IO\Exception\ExceptionInterface) {
            // @mago-expect lint:no-empty-catch-clause - Ignore read errors on redirect response bodies.
        }
    }
}
