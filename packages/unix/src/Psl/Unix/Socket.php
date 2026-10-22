<?php

declare(strict_types=1);

namespace Psl\Unix;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Network;
use Socket as NativeSocket;

use function is_resource;
use function socket_bind;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_export_stream;
use function socket_last_error;
use function socket_set_nonblock;
use function socket_strerror;
use function strlen;

use const AF_UNIX;
use const SOCK_STREAM;

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
     * path is a filesystem path. The socket is bound when it listens, or when it connects, which
     * needs ext-sockets: PHP's streams do not bind a Unix socket before connecting it.
     *
     * @param non-empty-string $path
     *
     * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one,
     *                                                    or is longer than the system can hold.
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
     * This consumes the socket; it cannot be reused after calling connect(). A socket that {@see bind()} named is
     * bound to that path before it connects, so the peer sees the path as the stream's address. That needs
     * ext-sockets: without it, such a connect fails before it creates a socket.
     *
     * @param non-empty-string $path
     *
     * @throws Network\Exception\InvalidArgumentException If the path contains a NUL byte other than a leading one,
     *                                                    or is longer than the system can hold.
     * @throws Network\Exception\RuntimeException If connect fails, if binding the socket fails, or if the socket was
     *                                             bound and ext-sockets is unavailable. When binding or connecting a
     *                                             bound socket fails, its code is the errno.
     * @throws CancelledException If the operation is cancelled.
     */
    public function connect(
        string $path,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): StreamInterface {
        $this->ensureNotConsumed();

        Internal\assert_valid_path($path);

        $this->consumed = true;

        if (null !== $this->bindPath) {
            return self::connectBound($this->bindPath, $path, $cancellation);
        }

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

    /**
     * Create a socket bound to the given path, connect it to the remote path, and return its stream.
     *
     * PHP's streams skip `bindto` for a Unix socket (main/streams/xp_socket.c), so the socket is created, bound and
     * connected with ext-sockets, then exported into a stream. Like any Unix connect, it never waits: the socket is
     * non-blocking, so a listener whose backlog is full fails it at once, with the errno, instead of blocking the
     * process. A socket that fails to bind or connect is closed; the file a successful bind created stays, as for a
     * listener's path.
     *
     * @param non-empty-string $bindPath
     * @param non-empty-string $path
     *
     * @throws Network\Exception\InvalidArgumentException If a path is longer than ext-sockets can hold, which only a
     *                                                    name that fills the whole of `sun_path` is.
     * @throws Network\Exception\RuntimeException If ext-sockets is unavailable, or creating, binding or connecting
     *                                             the socket fails.
     * @throws CancelledException If the operation is cancelled.
     */
    private static function connectBound(
        string $bindPath,
        string $path,
        CancellationTokenInterface $cancellation,
    ): StreamInterface {
        if (!Internal\sockets_available()) {
            throw new Network\Exception\RuntimeException(
                'Connecting a Unix socket bound to "' . $bindPath . '" needs ext-sockets.',
            );
        }

        // ext-sockets keeps one byte of `sun_path` free even for a name in the abstract namespace.
        $maximum = Internal\PathLimit::maximumSocketsLength();
        if (null !== $maximum && (strlen($bindPath) > $maximum || strlen($path) > $maximum)) {
            throw new Network\Exception\InvalidArgumentException(
                'Unix socket path must not be longer than ' . $maximum . ' bytes to bind a client on this system.',
            );
        }

        if ($cancellation->cancellable) {
            $cancellation->throwIfCancelled();
        }

        $socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (false === $socket) {
            $errno = socket_last_error();

            throw new Network\Exception\RuntimeException(
                'Failed to create a Unix socket: ' . socket_strerror($errno) . '.',
                $errno,
            );
        }

        $exported = false;

        try {
            if (!@socket_bind($socket, $bindPath)) {
                throw self::failure($socket, 'Failed to bind Unix socket to "' . $bindPath . '"');
            }

            if (!@socket_set_nonblock($socket) || !@socket_connect($socket, $path)) {
                throw self::failure($socket, 'Failed to connect to client "unix://' . $path . '"');
            }

            $stream = @socket_export_stream($socket);
            if (!is_resource($stream)) {
                throw self::failure($socket, 'Failed to export the Unix socket connected to "unix://' . $path . '"');
            }

            // The exported stream owns the descriptor from here on.
            $exported = true;
        } finally {
            if (!$exported) {
                socket_close($socket);
            }
        }

        return Internal\Stream::connected($stream, $path);
    }

    /**
     * The exception for a call on the socket that failed, with the socket's errno as its code.
     *
     * @param non-empty-string $message What failed, without the reason.
     */
    private static function failure(NativeSocket $socket, string $message): Network\Exception\RuntimeException
    {
        if (!Internal\sockets_available()) {
            return new Network\Exception\RuntimeException($message . '.');
        }

        $errno = socket_last_error($socket);

        return new Network\Exception\RuntimeException($message . ': ' . socket_strerror($errno) . '.', $errno);
    }

    /**
     * Throw if the socket was already consumed by connect() or listen().
     *
     * @throws Network\Exception\RuntimeException If the socket was already consumed.
     */
    private function ensureNotConsumed(): void
    {
        if ($this->consumed) {
            throw new Network\Exception\RuntimeException('Socket has already been consumed by connect() or listen().');
        }
    }
}
