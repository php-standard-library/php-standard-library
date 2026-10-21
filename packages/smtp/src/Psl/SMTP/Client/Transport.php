<?php

declare(strict_types=1);

namespace Psl\SMTP\Client;

use Normalizer;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\DateTime\Duration;
use Psl\DateTime\FormatPattern;
use Psl\IO;
use Psl\Message;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\MessageInterface;
use Psl\Network;
use Psl\Punycode;
use Psl\SMTP\Capability;
use Psl\SMTP\Command;
use Psl\SMTP\Exception;
use Psl\SMTP\Internal;
use Psl\SMTP\Reply;
use Psl\SMTP\Security;
use Psl\TCP;
use Psl\TLS;
use WeakMap;

use function array_shift;
use function explode;
use function implode;
use function mb_strtolower;
use function preg_match;
use function strlen;
use function strrpos;
use function substr;

/**
 * High-level SMTP transport with connection pooling, TLS, and authentication.
 *
 * Manages the full SMTP lifecycle: connect -> EHLO -> [STARTTLS -> EHLO] -> [AUTH] -> send -> RSET.
 * Handles BDAT chunking (RFC 3030), dot-stuffing, Punycode IDN encoding, DSN parameters,
 * 8BITMIME/SMTPUTF8 negotiation, and SMTP pipelining (RFC 2920).
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5321
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:excessive-nesting
 */
final class Transport implements TransportInterface
{
    /**
     * Tracks which pooled connections have been initialized (post-EHLO+TLS+AUTH).
     *
     * @var WeakMap<Network\StreamInterface, bool>
     */
    private WeakMap $initialized;

    private readonly TCP\SocketPoolInterface $pool;
    private readonly TLS\Connector $tlsConnector;

    public function __construct(
        private readonly TransportConfiguration $configuration,
        private readonly null|Authentication\AuthenticatorInterface $authenticator = null,
    ) {
        $this->tlsConnector = new TLS\Connector($configuration->tlsClientConfiguration);

        $tcpConnector = new TCP\Connector($configuration->connectConfiguration);
        $this->pool = new TCP\SocketPool(match ($configuration->security) {
            Security::TLS => new TLS\TCPConnector($tcpConnector, $this->tlsConnector),
            default => $tcpConnector,
        });

        $this->initialized = new WeakMap();
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\PossibleAttackException If the envelope, message, DSN, or configuration contain CRLF or null bytes that could be used for SMTP command injection.
     * @throws Exception\ConnectionException If the connection cannot be established, the TLS upgrade fails, or the connection fails during the exchange, for example because the server reset it.
     * @throws Exception\TimeoutException If the connection times out.
     * @throws Exception\ProtocolException If the server sends an unexpected or malformed response, or closes the connection.
     * @throws Exception\AuthenticationException If authentication fails or the mechanism is unsupported.
     * @throws Exception\TransmissionException If the server rejects the sender, a recipient, or the message data.
     * @throws IO\Exception\RuntimeException If reading the message content fails.
     * @throws CancelledException If the operation is cancelled.
     */
    public function send(
        Envelope $envelope,
        MessageInterface $message,
        SendConfiguration $sendConfiguration = new SendConfiguration(),
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): DeliveryReport {
        $stream = $this->checkout($cancellation);

        $connection = new Connection($stream);

        try {
            if (!$this->isInitialized($stream)) {
                $this->initialize($connection, $cancellation);
                $this->markInitialized($stream);
            }

            $content = $this->prepare($connection, $envelope, $message, $cancellation);
            $rejectedRecipients = $this->transmit($connection, $envelope, $content, $sendConfiguration, $cancellation);
        } catch (Exception\RuntimeException|IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            $this->pool->clear($stream);
            $this->forgetInitialized($stream);

            throw $e;
        }

        $this->pool->checkin($stream);

        return new DeliveryReport($rejectedRecipients);
    }

    /**
     * Close all pooled connections.
     */
    public function close(): void
    {
        $this->pool->close();
        $this->initialized = new WeakMap();
    }

    /**
     * Read the greeting, identify the client, upgrade the connection to TLS if configured, and authenticate.
     *
     * A failure of the connection is reported as a connection exception, with the IO or Network exception as its
     * previous exception.
     *
     * @throws Exception\PossibleAttackException If the local hostname contains CRLF or null bytes.
     * @throws Exception\ConnectionException If the greeting is not 220, the TLS upgrade fails, or the connection fails.
     * @throws Exception\ProtocolException If the server sends an unexpected or malformed response, or closes the connection.
     * @throws Exception\AuthenticationException If authentication fails or the mechanism is unsupported.
     * @throws CancelledException If the operation is cancelled.
     */
    private function initialize(Connection $connection, CancellationTokenInterface $cancellation): void
    {
        try {
            $connection->readGreeting($cancellation);

            $this->doEhlo($connection, $cancellation);
            if ($this->configuration->security === Security::StartTLS) {
                $connection->startTls($this->tlsConnector, $cancellation);
                $this->doEhlo($connection, $cancellation);
            }

            if ($this->authenticator !== null) {
                $this->authenticator->authenticate($connection, $cancellation);
            }
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            throw Exception\ConnectionException::forConnectionLost($e);
        }
    }

    /**
     * Serialize the message for transmission.
     *
     * Without SMTPUTF8, the headers are checked for raw UTF-8 first, and a multipart or embedded message body is
     * buffered so that its nested headers can be checked too. This reads the caller's message and does not use the
     * connection: a failure to read or buffer the message passes through unchanged.
     *
     * @throws Exception\TransmissionException If the message or the envelope needs SMTPUTF8 and the server does not support it.
     * @throws IO\Exception\RuntimeException If reading or buffering the message content fails.
     * @throws CancelledException If the operation is cancelled.
     */
    private function prepare(
        Connection $connection,
        Envelope $envelope,
        MessageInterface $message,
        CancellationTokenInterface $cancellation,
    ): Internal\MessageReadHandle {
        $data = Message\serialize($message);
        if (!$connection->supportsCapability(Capability::SMTPUTF8)) {
            $reader = new IO\Reader($data);
            $headers = $reader->readUntil("\r\n\r\n", $cancellation) ?? '';
            if (preg_match('/[\x80-\xff]/', $headers) === 1) {
                throw Exception\TransmissionException::forUnsupportedCapability(Capability::SMTPUTF8);
            }

            $body = $reader;
            $mediaType = $message->content->mediaType;
            if ($mediaType->type === 'multipart' || $mediaType->type === 'message') {
                $body = IO\spool();
                IO\copy($reader, $body, $cancellation);
                $body->seek(0);
                Internal\HeaderChecker::check(
                    $body,
                    $mediaType,
                    $message->content->headers->get('Content-Transfer-Encoding'),
                    $cancellation,
                );
                $body->seek(0);
            }

            $data = new IO\ConcatReadHandle(new IO\MemoryHandle($headers . "\r\n\r\n"), $body);
            foreach ([$envelope->sender, ...$envelope->recipients] as $mailbox) {
                if ($mailbox !== null && preg_match('/[\x80-\xff]/', $mailbox->localPart) === 1) {
                    throw Exception\TransmissionException::forUnsupportedCapability(Capability::SMTPUTF8);
                }
            }
        }

        return new Internal\MessageReadHandle($data);
    }

    /**
     * Run the mail transaction: MAIL FROM, RCPT TO, the message data, then RSET.
     *
     * A failure of the connection is reported as a connection exception, with the IO or Network exception as its
     * previous exception. A failure to read the message content is the caller's, and passes through unchanged.
     *
     * @return list<array{Mailbox, Reply}> The rejected recipients.
     *
     * @throws Exception\PossibleAttackException If the envelope or DSN parameters contain CRLF or null bytes.
     * @throws Exception\ConnectionException If the connection fails.
     * @throws Exception\ProtocolException If the server sends an unexpected or malformed response, or closes the connection.
     * @throws Exception\TransmissionException If the server rejects the sender, a recipient, or the message data.
     * @throws IO\Exception\RuntimeException If reading the message content fails.
     * @throws CancelledException If the operation is cancelled.
     */
    private function transmit(
        Connection $connection,
        Envelope $envelope,
        Internal\MessageReadHandle $content,
        SendConfiguration $sendConfiguration,
        CancellationTokenInterface $cancellation,
    ): array {
        try {
            $chunking = $this->configuration->chunking && $connection->supportsCapability(Capability::Chunking);
            $pipelining = $this->configuration->pipelining && $connection->supportsCapability(Capability::Pipelining);
            $partial = $this->configuration->allowPartialSuccess;
            $mailFromCommand = $this->buildMailFromCommand($connection, $envelope, $sendConfiguration);

            $rcptToCommands = [];
            foreach ($envelope->recipients as $recipient) {
                $rcptToCommands[] = $this->buildRcptToCommand($recipient, $sendConfiguration);
            }

            /** @var list<array{Mailbox, Reply}> $rejectedRecipients */
            $rejectedRecipients = [];
            $acceptedCount = 0;

            if ($pipelining) {
                $connection->writeCommand($mailFromCommand, $cancellation);
                foreach ($rcptToCommands as $rcptToCommand) {
                    $connection->writeCommand($rcptToCommand, $cancellation);
                }

                if (!$chunking) {
                    $connection->writeCommand(new Command('DATA'), $cancellation);
                }

                $mailFromReply = $connection->readReply($cancellation);
                if (!$mailFromReply->isPositiveCompletion()) {
                    throw Exception\TransmissionException::forSenderRejected(
                        $envelope->sender->address ?? '<>',
                        $mailFromReply->code,
                        $mailFromReply->message,
                    );
                }

                foreach ($envelope->recipients as $recipient) {
                    $rcptReply = $connection->readReply($cancellation);
                    if (!$rcptReply->isPositiveCompletion()) {
                        if (!$partial) {
                            throw Exception\TransmissionException::forRecipientRejected(
                                $recipient->address,
                                $rcptReply->code,
                                $rcptReply->message,
                            );
                        }

                        $rejectedRecipients[] = [$recipient, $rcptReply];
                    } else {
                        $acceptedCount++;
                    }
                }

                if ($partial && $acceptedCount === 0) {
                    throw Exception\TransmissionException::forAllRecipientsRejected();
                }

                if (!$chunking) {
                    $dataReply = $connection->readReply($cancellation);
                    if ($dataReply->code !== 354) {
                        throw Exception\TransmissionException::forDataRejected($dataReply->code, $dataReply->message);
                    }
                }
            } else {
                $mailFromReply = $connection->sendCommand($mailFromCommand, $cancellation);
                if (!$mailFromReply->isPositiveCompletion()) {
                    throw Exception\TransmissionException::forSenderRejected(
                        $envelope->sender->address ?? '<>',
                        $mailFromReply->code,
                        $mailFromReply->message,
                    );
                }

                foreach ($envelope->recipients as $recipient) {
                    /** @var Command $rcptToCommand */
                    $rcptToCommand = array_shift($rcptToCommands);
                    $rcptReply = $connection->sendCommand($rcptToCommand, $cancellation);
                    if (!$rcptReply->isPositiveCompletion()) {
                        if (!$partial) {
                            throw Exception\TransmissionException::forRecipientRejected(
                                $recipient->address,
                                $rcptReply->code,
                                $rcptReply->message,
                            );
                        }

                        $rejectedRecipients[] = [$recipient, $rcptReply];
                    } else {
                        $acceptedCount++;
                    }
                }

                if ($partial && $acceptedCount === 0) {
                    throw Exception\TransmissionException::forAllRecipientsRejected();
                }

                if (!$chunking) {
                    $dataReply = $connection->sendCommand(new Command('DATA'), $cancellation);
                    if ($dataReply->code !== 354) {
                        throw Exception\TransmissionException::forDataRejected($dataReply->code, $dataReply->message);
                    }
                }
            }

            if ($chunking) {
                $this->transmitChunked($connection, $content, $cancellation);
            } else {
                IO\copy(new Internal\DotStuffingReadHandle($content), $connection, $cancellation);

                $finalReply = $connection->readReply($cancellation);
                if (!$finalReply->isPositiveCompletion()) {
                    throw Exception\TransmissionException::forDataRejected($finalReply->code, $finalReply->message);
                }
            }

            $reply = $connection->sendCommand(new Command('RSET'), $cancellation);
            if (!$reply->isPositiveCompletion()) {
                throw Exception\ProtocolException::forUnexpectedCode(250, $reply->code, $reply->message);
            }
        } catch (IO\Exception\RuntimeException|Network\Exception\RuntimeException $e) {
            // Reading the caller's message failed, not the connection.
            if ($e === $content->failure) {
                throw $e;
            }

            throw Exception\ConnectionException::forConnectionLost($e);
        }

        return $rejectedRecipients;
    }

    /**
     * Transmit message data using BDAT chunking per RFC 3030.
     *
     * Reads chunks from the serialized message stream and sends each as a
     * BDAT command. The final chunk includes the LAST parameter.
     *
     * @throws Exception\TransmissionException If the server rejects a chunk.
     * @throws CancelledException If the operation is cancelled.
     */
    private function transmitChunked(
        Connection $connection,
        IO\ReadHandleInterface $stream,
        CancellationTokenInterface $cancellation,
    ): void {
        $chunkSize = $this->configuration->chunkSize;

        while (true) {
            $chunk = $stream->read($chunkSize, $cancellation);

            if ($chunk === '' || $stream->reachedEndOfDataSource()) {
                $connection->writeCommand(new Command('BDAT', strlen($chunk) . ' LAST'), $cancellation);
                if ($chunk !== '') {
                    $connection->writeAll($chunk, $cancellation);
                }

                $reply = $connection->readReply($cancellation);
                if (!$reply->isPositiveCompletion()) {
                    throw Exception\TransmissionException::forDataRejected($reply->code, $reply->message);
                }

                break;
            }

            $connection->writeCommand(new Command('BDAT', (string) strlen($chunk)), $cancellation);
            $connection->writeAll($chunk, $cancellation);

            $reply = $connection->readReply($cancellation);
            if (!$reply->isPositiveCompletion()) {
                throw Exception\TransmissionException::forDataRejected($reply->code, $reply->message);
            }
        }
    }

    /**
     * Build the MAIL FROM command with SMTP extension parameters.
     */
    private function buildMailFromCommand(
        Connection $connection,
        Envelope $envelope,
        SendConfiguration $config,
    ): Command {
        $address = $envelope->sender !== null ? $this->encodeAddress($envelope->sender->address) : '';

        $params = '';

        if ($connection->supportsCapability(Capability::EightBitMIME)) {
            $params .= ' BODY=8BITMIME';
        }

        if ($connection->supportsCapability(Capability::SMTPUTF8)) {
            $params .= ' SMTPUTF8';
        }

        if ($config->dsnReturn !== null) {
            $params .= ' RET=' . $config->dsnReturn;
        }

        if ($config->dsnEnvelopeId !== null) {
            $params .= ' ENVID=' . $config->dsnEnvelopeId;
        }

        if ($config->requireTls) {
            if ($this->configuration->security === Security::None) {
                throw Exception\TransmissionException::forTlsRequired();
            }

            if (!$connection->supportsCapability(Capability::RequireTLS)) {
                throw Exception\TransmissionException::forUnsupportedCapability(Capability::RequireTLS);
            }

            $params .= ' REQUIRETLS';
        }

        if ($config->priority !== null && $connection->supportsCapability(Capability::MTPriority)) {
            $params .= ' MT-PRIORITY=' . $config->priority->value;
        }

        if ($config->deliverBy !== null && $connection->supportsCapability(Capability::DeliverBy)) {
            $seconds = (int) $config->deliverBy->deadline->getTotalSeconds();
            $params .= ' BY=' . $seconds . ';' . $config->deliverBy->mode->value;
        }

        if ($config->futureRelease !== null && $connection->supportsCapability(Capability::FutureRelease)) {
            if ($config->futureRelease instanceof Duration) {
                $params .= ' HOLDFOR=' . (int) $config->futureRelease->getTotalSeconds();
            } else {
                $params .= ' HOLDUNTIL=' . $config->futureRelease->format(FormatPattern::Iso8601WithoutMicroseconds);
            }
        }

        return new Command('MAIL', 'FROM:<' . $address . '>' . $params);
    }

    /**
     * Build an RCPT TO command with optional DSN NOTIFY parameter.
     */
    private function buildRcptToCommand(Message\Address\Mailbox $recipient, SendConfiguration $config): Command
    {
        $address = $this->encodeAddress($recipient->address);

        $params = '';
        if ($config->dsnNotify !== null) {
            $params = ' NOTIFY=' . $config->dsnNotify;
        }

        return new Command('RCPT', 'TO:<' . $address . '>' . $params);
    }

    /**
     * Encode the domain part of an email address with Punycode (IDN) if needed.
     */
    private function encodeAddress(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return $address;
        }

        $domain = substr($address, $at + 1);
        if ($domain[0] === '[') {
            return $address;
        }

        $labels = [];
        foreach (explode('.', $domain) as $label) {
            $label = Normalizer::normalize(mb_strtolower($label, 'UTF-8'), Normalizer::FORM_C);
            if ($label === false) {
                throw Exception\TransmissionException::forInvalidAddress($address);
            }

            $label = preg_match('/[\x80-\xff]/', $label) === 1 ? 'xn--' . Punycode\encode($label) : $label;
            if (strlen($label) > 63) {
                throw Exception\TransmissionException::forInvalidAddress($address);
            }

            $labels[] = $label;
        }

        return substr($address, 0, $at + 1) . implode('.', $labels);
    }

    /**
     * @throws Exception\ConnectionException If the connection cannot be established.
     * @throws Exception\TimeoutException If the connection times out.
     */
    private function checkout(CancellationTokenInterface $cancellation): TCP\StreamInterface
    {
        $port = $this->configuration->port ?? match ($this->configuration->security) {
            Security::StartTLS => 587,
            Security::TLS => 465,
            Security::None => 25,
        };

        try {
            return $this->pool->checkout($this->configuration->host, $port, $cancellation);
        } catch (CancelledException $e) {
            throw Exception\TimeoutException::forConnection($this->configuration->host, $port, $e);
        } catch (Network\Exception\RuntimeException $e) {
            throw Exception\ConnectionException::forConnectionFailed($this->configuration->host, $port, $e);
        }
    }

    /**
     * Send EHLO, falling back to HELO if EHLO is rejected per RFC 5321 SS3.2.
     *
     * @throws Exception\ProtocolException If both EHLO and HELO fail.
     */
    private function doEhlo(Connection $connection, CancellationTokenInterface $cancellation): void
    {
        $response = $connection->ehlo($this->configuration->localHostname, $cancellation);
        if ($response->isPositiveCompletion()) {
            return;
        }

        $response = $connection->helo($this->configuration->localHostname, $cancellation);
        if (!$response->isPositiveCompletion()) {
            throw Exception\ProtocolException::forUnexpectedCode(250, $response->code, $response->message);
        }
    }

    private function isInitialized(Network\StreamInterface $stream): bool
    {
        return ($this->initialized[$stream] ?? false) === true;
    }

    private function markInitialized(Network\StreamInterface $stream): void
    {
        $this->initialized[$stream] = true;
    }

    private function forgetInitialized(Network\StreamInterface $stream): void
    {
        unset($this->initialized[$stream]);
    }
}
