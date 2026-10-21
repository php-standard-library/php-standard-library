<?php

declare(strict_types=1);

namespace Psl\SMTP\Exception;

use Throwable;

/**
 * Thrown when SMTP authentication fails or the requested mechanism is unsupported.
 *
 * @api
 */
final class AuthenticationException extends RuntimeException
{
    private function __construct(string $message, null|Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public static function forRejected(string $mechanism, int $code, string $serverMessage): self
    {
        return new self('SMTP ' . $mechanism . ' authentication rejected: ' . $code . ' ' . $serverMessage . '.');
    }

    public static function forUnsupportedMechanism(string $mechanism): self
    {
        return new self('SMTP server does not support the \'' . $mechanism . '\' authentication mechanism.');
    }

    /**
     * The system could not generate the secure random nonce that the mechanism needs.
     *
     * The exception that reported the failure is the previous exception.
     */
    public static function forNonceGenerationFailed(string $mechanism, Throwable $previous): self
    {
        return new self(
            'SMTP ' . $mechanism . ' authentication failed: could not generate a secure random nonce.',
            $previous,
        );
    }
}
