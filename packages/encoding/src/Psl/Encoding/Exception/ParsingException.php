<?php

declare(strict_types=1);

namespace Psl\Encoding\Exception;

use function sprintf;

/**
 * @api
 */
final class ParsingException extends RuntimeException implements ExceptionInterface
{
    public static function forInvalidEncodedWord(string $text): self
    {
        return new self(sprintf('Failed to decode encoded-word payload: "%s".', $text));
    }

    public static function forUnsupportedCharset(string $charset): self
    {
        return new self(sprintf('Unsupported encoded-word charset: "%s".', $charset));
    }
}
