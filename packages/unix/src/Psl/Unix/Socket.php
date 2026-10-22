<?php

declare(strict_types=1);

namespace Psl\Unix;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;

/**
 * A Unix domain socket that can be configured before connecting or listening.
 *
 * @api
 */
final class Socket
{
    private bool $consumed = false;

    /**
     * @var null|non-empty-string
     */
    private null|string $bindPath = null;

    private function __construct() {}

    /**
     * Create a new Unix domain socket.
     *
     * @throws Network\Exception\RuntimeException If the platform is Windows.
     */
    public static function create(): self
    {
        Internal\assert_not_windows();

        return new self();
    }

    /**
     * Bind the socket to a path.
     *
     * A path starting with a NUL byte names a socket in the Linux abstract namespace; any other
     * path is a filesystem path.
     *
     * @param non-empty-string $path
     *
     * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one.
     */
    public function bind(string $path): void
    {
        $this->ensureNotConsumed();

        Internal\assert_valid_path($path);

        $this->bindPath = $path;
    }

    /**
     * Connect the socket to a remote path and return a Unix stream.
     *
     * This consumes the socket; it cannot be reused after calling connect().
     *
     * @param non-empty-string $path
     *
     * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one.
     * @throws Network\Exception\RuntimeException If connect fails.
     * @throws CancelledException If the operation is cancelled.
     */
    public function connect(
        string $path,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        $this->ensureNotConsumed();

        Internal\assert_valid_path($path);

        $this->consumed = true;

        $stream = Network\Internal\socket_connect("unix://{$path}", cancellation: $cancellation);

        return Internal\Stream::connected($stream, $path);
    }

    /**
     * Start listening for incoming connections and return a Unix listener.
     *
     * This consumes the socket; it cannot be reused after calling listen().
     *
     * @throws Network\Exception\RuntimeException If listen fails.
     */
    public function listen(ListenConfiguration $configuration = new ListenConfiguration()): ListenerInterface
    {
        $this->ensureNotConsumed();
        $this->consumed = true;

        if ($this->bindPath === null) {
            throw new Network\Exception\RuntimeException(
                'Cannot listen without binding to a path first. Call bind() before listen().',
            );
        }

        $context = ['socket' => [
            'backlog' => $configuration->backlog,
        ]];

        $stream = Network\Internal\server_listen("unix://{$this->bindPath}", $context);

        return new Internal\Listener($stream, Network\Address::unix($this->bindPath), $configuration->idleConnections);
    }

    /**
     * Get the local address the socket is bound to.
     *
     * @throws Network\Exception\RuntimeException If no path has been bound.
     */
    public function getLocalAddress(): Network\Address
    {
        $this->ensureNotConsumed();

        if ($this->bindPath === null) {
            throw new Network\Exception\RuntimeException('Socket has not been bound to a path. Call bind() first.');
        }

        return Network\Address::unix($this->bindPath);
    }

    private function ensureNotConsumed(): void
    {
        if ($this->consumed) {
            throw new Network\Exception\RuntimeException('Socket has already been consumed by connect() or listen().');
        }
    }
}
