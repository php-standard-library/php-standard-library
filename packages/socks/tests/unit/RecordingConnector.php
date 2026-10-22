<?php

declare(strict_types=1);

namespace Psl\Socks\Tests\Unit;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\TCP;

/**
 * A connector that keeps every stream it returns, as a pool would, so that a test can check what became of it.
 */
final class RecordingConnector implements TCP\ConnectorInterface
{
    /**
     * The streams the connector returned, oldest first.
     *
     * @var list<TCP\StreamInterface>
     */
    public array $streams = [];

    /**
     * Create a connector that connects through the given one.
     */
    public function __construct(
        /**
         * The connector that makes the connections.
         */
        private readonly TCP\ConnectorInterface $connector = new TCP\Connector(),
    ) {}

    /**
     * Connect through the inner connector, and keep the stream.
     */
    #[Override]
    public function connect(
        string $host,
        int $port,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): TCP\StreamInterface {
        $stream = $this->connector->connect($host, $port, $cancellation);
        $this->streams[] = $stream;

        return $stream;
    }
}
