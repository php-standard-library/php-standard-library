<?php

declare(strict_types=1);

namespace Psl\H2;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Header;
use Psl\IO;

/**
 * Client-side HTTP/2 connection over a read/write stream.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9113
 *
 * @mago-expect analysis:deprecated-class
 *
 * @api
 */
final class ClientConnection implements ClientConnectionInterface
{
    use Internal\ConnectionTrait;

    public readonly Role $role;

    /**
     * Create a new client-side HTTP/2 connection.
     *
     * @param IO\ReadHandleInterface&IO\WriteHandleInterface $handle The underlying transport stream.
     * @param null|IO\Reader $reader Optional pre-existing reader for the handle (e.g. when upgrading from HTTP/1.1).
     */
    public function __construct(
        private readonly IO\ReadHandleInterface&IO\WriteHandleInterface $handle,
        ClientConfiguration|Configuration $configuration = new ClientConfiguration(),
        null|IO\Reader $reader = null,
    ) {
        $this->role = Role::Client;
        $this->writeBufferThreshold = $configuration->writeBufferThreshold;
        $this->reader = $reader ?? new IO\Reader($handle);
        $this->drainReader = null !== $reader;

        $maxReceiveWindowSize = $configuration instanceof Configuration ? $configuration->maxReceiveWindowSize : null;

        $this->stateMachine = new StateMachine(
            true,
            $configuration->settings,
            $configuration->rateLimiter?->fresh(),
            $configuration->maxHeaderBlockSize,
            $maxReceiveWindowSize !== null
                ? new Internal\BDPEstimator(
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

        $data = namespace\CONNECTION_PREFACE;
        foreach ($frames as $frame) {
            $data .= Frame\encode($frame);
        }

        $this->handle->writeAll($data, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendPriority(
        int $streamId,
        int $streamDependency = 0,
        int $weight = 16,
        bool $exclusive = false,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->sendPriority($streamId, $streamDependency, $weight, $exclusive);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendPriorityUpdate(
        int $streamId,
        string $fieldValue,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $frames = $this->stateMachine->sendPriorityUpdate($streamId, $fieldValue);
        $this->writeFrames($frames, $cancellation);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function sendExtendedConnect(
        int $streamId,
        string $protocol,
        string $scheme,
        string $authority,
        string $path,
        array $extraHeaders = [],
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $headers = [
            new Header(':method', 'CONNECT'),
            new Header(':protocol', $protocol),
            new Header(':scheme', $scheme),
            new Header(':authority', $authority),
            new Header(':path', $path),
            ...$extraHeaders,
        ];

        $this->sendHeaders($streamId, $headers, false, $cancellation);
    }
}
