<?php

declare(strict_types=1);

namespace Psl\SMTP\Exception;

use Throwable;

/**
 * Thrown when establishing or upgrading an SMTP connection fails, or when the connection fails during the exchange.
 *
 * @api
 */
final class ConnectionException extends RuntimeException
{
    private function __construct(string $message, null|Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public static function forConnectionFailed(string $host, int $port, null|Throwable $previous = null): self
    {
        return new self('Failed to connect to SMTP server \'' . $host . ':' . $port . '\'.', $previous);
    }

    public static function forTLSUpgradeFailed(null|Throwable $previous = null): self
    {
        return new self('Failed to upgrade SMTP connection to TLS via STARTTLS.', $previous);
    }

    /**
     * The connection failed during the exchange: the server reset or closed it, or a read or write on it failed.
     *
     * The IO or Network exception that reported the failure is the previous exception.
     */
    public static function forConnectionLost(Throwable $previous): self
    {
        return new self('Lost the connection to the SMTP server.', $previous);
    }

    public static function forUnexpectedGreeting(int $code, string $message): self
    {
        return new self('SMTP server sent unexpected greeting: ' . $code . ' ' . $message . '.');
    }
}
