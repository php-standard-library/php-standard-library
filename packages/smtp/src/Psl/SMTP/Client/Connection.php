<?php

declare(strict_types=1);

namespace Psl\SMTP\Client;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Network;
use Psl\SMTP\Capability;
use Psl\SMTP\Command;
use Psl\SMTP\Exception;
use Psl\SMTP\Internal;
use Psl\SMTP\Reply;
use Psl\TLS;
use WeakMap;

use function array_key_exists;
use function explode;
use function str_contains;
use function strtoupper;
use function trim;

/**
 * Low-level SMTP protocol connection.
 *
 * Wraps a stream and speaks SMTP commands, parsing responses one at a time.
 * Tracks EHLO capabilities for feature detection.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5321
 *
 * @api
 */
final class Connection implements ConnectionInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;
    use IO\WriteHandleConvenienceMethodsTrait;

    private IO\Reader $reader;

    /** @var null|WeakMap<Network\StreamInterface, array<string, string>> */
    private static null|WeakMap $pooledCapabilities = null;

    private readonly Network\StreamInterface $capabilityStream;

    /**
     * Raw EHLO extension lines (uppercased keyword → rest of the line).
     *
     * @var array<string, string>
     */
    private array $capabilities = [];

    /**
     * The maximum message size advertised by the server, or null if not advertised.
     */
    public private(set) null|int $maxSize = null;

    public function __construct(
        private Network\StreamInterface $stream,
    ) {
        $this->reader = new IO\Reader($stream);
        $this->capabilityStream = $stream;
        self::$pooledCapabilities ??= new WeakMap();
        $this->capabilities = self::$pooledCapabilities[$stream] ?? [];
        $size = $this->capabilities['SIZE'] ?? '';
        $this->maxSize = $size !== '' ? (int) $size : null;
    }

    /**
     * {@inheritDoc}
     */
    public function helo(
        string $hostname,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Reply {
        $response = $this->sendCommand(new Command('HELO', $hostname), $cancellation);
        if ($response->isPositiveCompletion()) {
            $this->capabilities = [];
            $this->maxSize = null;
            unset(self::$pooledCapabilities[$this->capabilityStream]);
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function ehlo(
        string $hostname,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Reply {
        $response = $this->sendCommand(new Command('EHLO', $hostname), $cancellation);
        if (!$response->isPositiveCompletion()) {
            return $response;
        }

        $this->capabilities = [];
        $this->maxSize = null;

        $lines = explode("\n", $response->message);
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }

            $line = trim($line, " \t");
            if ($line === '') {
                continue;
            }

            $parts = explode(' ', $line, 2);
            $keyword = strtoupper($parts[0]);
            $this->capabilities[$keyword] = $parts[1] ?? '';

            if ($keyword === 'SIZE' && ($parts[1] ?? '') !== '') {
                $this->maxSize = (int) $parts[1];
            }
        }

        self::$pooledCapabilities[$this->capabilityStream] = $this->capabilities;

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function startTls(
        TLS\Connector $connector,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $response = $this->sendCommand(new Command('STARTTLS'), $cancellation);
        if ($response->code !== 220) {
            throw Exception\ConnectionException::forTLSUpgradeFailed();
        }

        try {
            $tlsStream = $connector->connect($this->stream, cancellation: $cancellation);
        } catch (TLS\Exception\HandshakeFailedException|Network\Exception\RuntimeException $e) {
            throw Exception\ConnectionException::forTLSUpgradeFailed($e);
        }

        $this->stream = $tlsStream;
        $this->reader = new IO\Reader($tlsStream);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsCapability(string|Capability $capability): bool
    {
        $keyword = $capability instanceof Capability ? $capability->value : strtoupper($capability);

        return array_key_exists($keyword, $this->capabilities);
    }

    /**
     * {@inheritDoc}
     */
    public function getCapabilityValue(string|Capability $capability): null|string
    {
        $keyword = $capability instanceof Capability ? $capability->value : strtoupper($capability);

        return $this->capabilities[$keyword] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function sendCommand(
        string|Command $command,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): Reply {
        $this->writeCommand($command, $cancellation);

        return $this->readReply($cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function writeCommand(
        string|Command $command,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $line = $command instanceof Command ? $command->toString() : $command;

        if (str_contains($line, "\0")) {
            throw Exception\PossibleAttackException::forNullByteInjection();
        }

        if (str_contains($line, "\r") || str_contains($line, "\n")) {
            throw Exception\PossibleAttackException::forCRLFInjection();
        }

        $this->stream->writeAll($line . "\r\n", $cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function readReply(CancellationTokenInterface $cancellation = new NullCancellationToken()): Reply
    {
        return Internal\parse_reply($this->reader, $cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function readGreeting(CancellationTokenInterface $cancellation = new NullCancellationToken()): Reply
    {
        return Internal\read_greeting($this->reader, $cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->reader->tryRead($maxBytes);
    }

    /**
     * {@inheritDoc}
     */
    public function read(
        null|int $maxBytes = null,
        null|CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->reader->read($maxBytes, $cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function reachedEndOfDataSource(): bool
    {
        return $this->reader->reachedEndOfDataSource();
    }

    /**
     * {@inheritDoc}
     */
    public function tryWrite(string $bytes): int
    {
        return $this->stream->tryWrite($bytes);
    }

    /**
     * {@inheritDoc}
     */
    public function write(
        string $bytes,
        null|CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): int {
        return $this->stream->write($bytes, $cancellation);
    }

    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->stream->writeAll($bytes, $cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        $this->stream->close();
    }

    /**
     * {@inheritDoc}
     */
    public function isClosed(): bool
    {
        return $this->stream->isClosed();
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalAddress(): Network\Address
    {
        return $this->stream->getLocalAddress();
    }

    /**
     * {@inheritDoc}
     */
    public function getPeerAddress(): Network\Address
    {
        return $this->stream->getPeerAddress();
    }

    /**
     * {@inheritDoc}
     */
    public function peek(int $maxBytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        return $this->stream->peek($maxBytes, $cancellation);
    }

    /**
     * {@inheritDoc}
     */
    public function shutdown(): void
    {
        $this->stream->shutdown();
    }

    /**
     * {@inheritDoc}
     */
    public function getStream(): mixed
    {
        return $this->stream->getStream();
    }
}
