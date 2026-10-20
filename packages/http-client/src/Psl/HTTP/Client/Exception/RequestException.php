<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Exception;

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Throwable;

/**
 * Thrown when the request itself is invalid or cannot be dispatched.
 *
 * This exception reports invalid request fields, framing, or body replay.
 * Most checks run before sending. A streamed body's Content-Length mismatch
 * can surface after the client has sent headers or part of the body.
 *
 * Two categories of request errors are covered:
 *
 * 1. **Missing URL**: The request has no URL and no base URL is configured in
 *    the {@see Client\ClientConfiguration}. Thrown by {@see Client\Client}, {@see Client\Connection\Connector}, and
 *    {@see Client\Connection\PooledConnector},
 *
 * 2. **Invalid request structure**: The request violates HTTP semantics,
 *    such as a TRACE request that includes a body (forbidden by RFC 9110
 *    Section 9.3.8), or a request with a host header mismatch. Thrown by {@see Client\Client}
 *
 * @see RuntimeException Parent exception for all HTTP client errors.
 * @see ProtocolException For errors originating from the server response or transport protocol.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9110#section-9.3.8 TRACE Method
 *
 * @api
 */
final class RequestException extends RuntimeException
{
    /**
     * Create an exception for a request that has no URL and no base URL is configured.
     *
     * Thrown when URL resolution fails because neither the {@see Message\Request}
     * carries a URL nor the {@see Client\ClientConfiguration} provides a base
     * URL to resolve against.
     *
     * @param null|Throwable $previous Optional underlying cause, typically an exception
     *                                 from URL parsing when a base URL was present but
     *                                 resolution still failed.
     */
    public static function forMissingUrl(null|Throwable $previous = null): self
    {
        return new self(
            'Request URL could not be resolved: no URL on the request and no base URL in client configuration.',
            previous: $previous,
        );
    }

    /**
     * Create an exception for a structurally invalid request.
     *
     * Thrown when the request violates HTTP semantics in a way that prevents
     * the client from sending it, such as including a body on a TRACE request
     * or specifying conflicting host headers.
     *
     * @param string $detail Human-readable description of why the request is invalid.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc9110#section-9.3.8 TRACE requests must not include a body.
     */
    public static function forInvalidRequest(string $detail): self
    {
        return new self('Invalid request: ' . $detail);
    }
}
