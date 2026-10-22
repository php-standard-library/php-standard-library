<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Fixture;

use Closure;
use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Psl\TCP;
use RuntimeException;

use function fopen;

/**
 * A connector that connects to nothing: each connect() returns a new TCP stream over an in-memory resource, with the
 * requested host and port as its peer address.
 */
final class MemoryConnector implements TCP\ConnectorInterface
{
    /**
     * How many times connect() has been called.
     */
    public int $connects = 0;

    /**
     * Invoked by connect() with the stream it is about to return, right before it returns it.
     *
     * @var null|(Closure(TCP\StreamInterface): void)
     */
    public null|Closure $beforeReturn = null;

    /**
     * Return a new stream over an in-memory resource.
     *
     * @param non-empty-string $host
     * @param int<0, 65535> $port
     */
    #[Override]
    public function connect(
        string $host,
        int $port,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): TCP\StreamInterface {
        $this->connects++;

        $stream = self::stream(Network\Address::tcp($host, $port));
        if (null !== $this->beforeReturn) {
            ($this->beforeReturn)($stream);
        }

        return $stream;
    }

    /**
     * Create a stream over an in-memory resource, with the given peer address.
     */
    public static function stream(Network\Address $peerAddress): TCP\StreamInterface
    {
        $resource = fopen('php://memory', 'r+');
        if (false === $resource) {
            throw new RuntimeException('Failed to open an in-memory stream.');
        }

        return new TCP\Internal\Stream($resource, Network\Address::tcp('127.0.0.1', 0), $peerAddress);
    }
}
