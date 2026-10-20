<?php

declare(strict_types=1);

namespace Psl\H2;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Internal\BDPEstimator;
use Psl\H2\Internal\StateMachine;
use Psl\IO;

use function strlen;

/**
 * Server-side HTTP/2 connection over a read/write stream.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113
 *
 * @mago-expect analysis:deprecated-class
 *
 * @api
 */
final class ServerConnection implements ServerConnectionInterface
{
    use Internal\ConnectionTrait;

    public readonly Role $role;

    /**
     * Create a new server-side HTTP/2 connection.
     *
     * @param IO\ReadHandleInterface&IO\WriteHandleInterface $handle The underlying transport stream.
     * @param null|IO\Reader $reader Optional pre-existing reader for the handle (e.g. when upgrading from HTTP/1.1).
     */
    public function __construct(
        private readonly IO\ReadHandleInterface&IO\WriteHandleInterface $handle,
        ServerConfiguration|Configuration $configuration = new ServerConfiguration(),
        null|IO\Reader $reader = null,
    ) {
        $this->role = Role::Server;
        $this->writeBufferThreshold = $configuration->writeBufferThreshold;
        $this->reader = $reader ?? new IO\Reader($handle);

        $maxReceiveWindowSize = $configuration->maxReceiveWindowSize;

        $this->stateMachine = new StateMachine(
            false,
            $configuration->settings,
            $configuration->rateLimiter,
            $configuration->maxHeaderBlockSize,
            $maxReceiveWindowSize !== null
                ? new BDPEstimator(
                    $configuration->settings[Setting::InitialWindowSize->value]
                    ?? namespace\DEFAULT_INITIAL_WINDOW_SIZE,
                    $maxReceiveWindowSize,
                )
                : null,
        );
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function initialize(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        $frames = $this->stateMachine->initialize();

        $data = '';
        foreach ($frames as $frame) {
            $data .= Frame\encode($frame);
        }

        $this->handle->writeAll($data, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function readClientPreface(CancellationTokenInterface $cancellation = new NullCancellationToken()): void
    {
        /** @var positive-int $size */
        $size = strlen(namespace\CONNECTION_PREFACE);
        $received = $this->readTransportBytes($size, $cancellation);
        if ($received !== namespace\CONNECTION_PREFACE) {
            throw ProtocolException::forConnectionError('Invalid client connection preface');
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendHeadersWithStatus(
        int $streamId,
        string $status,
        iterable $headers,
        bool $endStream = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $encoded = $this->stateMachine->sendResponseHeadersEncoded($streamId, $status, $headers, $endStream);
        $this->notifyWindowWaiters($streamId);
        $this->write($encoded, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendPushPromise(
        int $associatedStreamId,
        int $promisedStreamId,
        array $headers,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->sendPushPromise($associatedStreamId, $promisedStreamId, $headers);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendAltSvc(
        int $streamId,
        string $origin,
        string $fieldValue,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->sendAltSvc($streamId, $origin, $fieldValue);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendOrigin(
        array $origins,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->sendOrigin($origins);
        $this->writeFrames($frames, $cancellation);
    }
}
