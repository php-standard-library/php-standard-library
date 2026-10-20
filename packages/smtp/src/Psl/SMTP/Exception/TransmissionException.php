<?php

declare(strict_types=1);

namespace Psl\SMTP\Exception;

use Psl\SMTP\Capability;

/**
 * Thrown when the server rejects the sender, a recipient, or the message data.
 *
 * @api
 */
final class TransmissionException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The server does not support an extension required for this message.
     */
    public static function forUnsupportedCapability(Capability $capability): self
    {
        return new self('SMTP server does not support the required ' . $capability->value . ' extension.');
    }

    public static function forTlsRequired(): self
    {
        return new self('REQUIRETLS needs a TLS session; configure TLS or STARTTLS.');
    }

    public static function forInvalidAddress(string $address): self
    {
        return new self('Invalid SMTP address domain: ' . $address . '.');
    }

    public static function forSenderRejected(string $address, int $code, string $serverMessage): self
    {
        return new self('SMTP server rejected sender \'' . $address . '\': ' . $code . ' ' . $serverMessage . '.');
    }

    public static function forRecipientRejected(string $address, int $code, string $serverMessage): self
    {
        return new self('SMTP server rejected recipient \'' . $address . '\': ' . $code . ' ' . $serverMessage . '.');
    }

    public static function forAllRecipientsRejected(): self
    {
        return new self('SMTP server rejected all recipients.');
    }

    public static function forDataRejected(int $code, string $serverMessage): self
    {
        return new self('SMTP server rejected message data: ' . $code . ' ' . $serverMessage . '.');
    }
}
