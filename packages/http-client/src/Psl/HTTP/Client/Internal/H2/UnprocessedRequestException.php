<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal\H2;

use Psl\HTTP\Client\Exception\RuntimeException;

/**
 * The peer guarantees that it did not process this request (RFC 9113 Section 8.7).
 *
 * @internal
 */
final class UnprocessedRequestException extends RuntimeException
{
    public function __construct(
        public readonly RuntimeException $failure,
    ) {
        parent::__construct($failure->getMessage(), previous: $failure);
    }
}
