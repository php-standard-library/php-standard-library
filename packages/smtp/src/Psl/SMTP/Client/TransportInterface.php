<?php

declare(strict_types=1);

namespace Psl\SMTP\Client;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Message\Envelope;
use Psl\Message\MessageInterface;
use Psl\SMTP\Exception;

/**
 * Contract for sending messages over SMTP.
 *
 * @api
 */
interface TransportInterface
{
    /**
     * Send a message with its envelope through the SMTP transport.
     *
     * Returns a {@see DeliveryReport} containing per-recipient rejection details.
     * When all recipients are accepted, the report has no rejections.
     *
     * Any failure of the exchange is an {@see Exception\RuntimeException}. A connection that fails during the
     * exchange, for example because the server reset it, is reported as an {@see Exception\ConnectionException}
     * whose previous exception is the IO or Network exception. A failure to read the message content is not a
     * failure of the exchange: it passes through unchanged.
     *
     * @throws Exception\PossibleAttackException If the envelope, message, DSN, or configuration contain CRLF or null bytes that could be used for SMTP command injection.
     * @throws Exception\RuntimeException If the connection cannot be established or fails, or the server rejects the exchange, breaks the protocol or closes the connection.
     * @throws IO\Exception\RuntimeException If reading the message content fails.
     * @throws CancelledException If the operation is cancelled.
     */
    public function send(
        Envelope $envelope,
        MessageInterface $message,
        SendConfiguration $sendConfiguration = new SendConfiguration(),
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): DeliveryReport;

    /**
     * Close all pooled connections.
     */
    public function close(): void;
}
