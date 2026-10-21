<?php

declare(strict_types=1);

namespace Psl\IO\Exception;

use Throwable;

use function sprintf;

/**
 * Thrown when a source holds more data than a configured byte limit allows.
 *
 * {@see \Psl\IO\BoundedReadHandle} throws it once it has seen a byte past its limit.
 *
 * @api
 */
final class LimitExceededException extends RuntimeException
{
    /**
     * @param int $limit The configured limit, in bytes.
     */
    private function __construct(
        string $message,
        public readonly int $limit,
        null|Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The data exceeded the configured limit of $limit bytes.
     *
     * @pure
     */
    public static function forLimit(int $limit): self
    {
        return new self(sprintf('Data exceeded the configured limit of %d bytes.', $limit), $limit);
    }
}
