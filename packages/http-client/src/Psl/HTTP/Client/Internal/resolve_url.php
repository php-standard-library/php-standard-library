<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal;

use Psl\URI;
use Psl\URL;

use function str_starts_with;

/**
 * Resolve a URI reference against a base URL per RFC 3986 Section 5.2.
 *
 * @param non-empty-string $reference The URI reference to resolve.
 *
 * @throws URL\Exception\InvalidURLException If the result is not a valid URL.
 *
 * @link https://www.rfc-editor.org/rfc/rfc3986#section-5.2
 *
 * @internal
 */
function resolve_url(string $reference, URL\URL $baseUrl): URL\URL
{
    try {
        if (str_starts_with($reference, ':')) {
            throw URI\Exception\InvalidURIException::forInvalidScheme('');
        }

        return URL\from_uri(URI\resolve($baseUrl->toURI(), URI\parse($reference)));
    } catch (URI\Exception\InvalidURIException $exception) {
        throw URL\Exception\InvalidURLException::forInvalidURI($exception->getMessage(), $exception);
    }
}
