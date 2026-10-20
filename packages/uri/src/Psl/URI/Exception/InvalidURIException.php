<?php

declare(strict_types=1);

namespace Psl\URI\Exception;

use Throwable;

/**
 * @api
 */
final class InvalidURIException extends InvalidArgumentException
{
    private function __construct(string $message, null|Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public static function forInvalidScheme(string $scheme): self
    {
        return new self('Invalid URI scheme "' . $scheme . '": must match ALPHA *( ALPHA / DIGIT / "+" / "-" / "." ).');
    }

    public static function forInvalidPercentEncoding(string $sequence): self
    {
        return new self('Invalid percent-encoding sequence "' . $sequence . '": expected %HEXHEX.');
    }

    public static function forInvalidPath(string $path): self
    {
        return new self(
            'Invalid URI path "' . $path . '": a relative path cannot contain a colon in its first segment.',
        );
    }

    public static function forInvalidHost(string $host, null|Throwable $previous = null): self
    {
        return new self('Invalid URI host "' . $host . '".', $previous);
    }

    public static function forInvalidPort(int|string $port): self
    {
        return new self('Invalid URI port "' . $port . '": must be an integer between 0 and 65535.');
    }

    public static function forNonASCII(): self
    {
        return new self('URI contains non-ASCII characters; use the IRI component for internationalized identifiers.');
    }
}
