<?php

declare(strict_types=1);

namespace Psl\File\Internal;

use Override;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\File;
use Psl\File\Lock;
use Psl\File\LockType;
use Psl\IO;
use Psl\IO\Exception;

use function error_get_last;
use function flock;
use function fseek;
use function min;
use function sprintf;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;
use const SEEK_END;

/**
 * @internal
 */
final class ResourceHandle extends IO\Internal\ResourceHandle implements
    File\WriteHandleInterface,
    File\ReadHandleInterface
{
    private string $path;

    /**
     * @param resource $stream
     */
    public function __construct(string $path, mixed $stream, bool $read, bool $write)
    {
        parent::__construct($stream, $read, $write, seek: true, close: true);

        $this->path = $path;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return int<0, max>
     *
     * @inheritDoc
     */
    #[Override]
    public function getSize(): int
    {
        if (null === $this->stream) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        // @codeCoverageIgnoreStart
        try {
            $position = $this->tell();
        } catch (IO\Exception\RuntimeException $previous) {
            throw new File\Exception\RuntimeException($previous->getMessage(), previous: $previous);
        }

        $result = @fseek($this->stream, 0, SEEK_END);
        if ($result === -1) {
            $error = error_get_last();

            throw new File\Exception\RuntimeException($error['message'] ?? 'unknown error.');
        }

        try {
            $size = $this->tell();
        } catch (IO\Exception\RuntimeException $previous) {
            throw new File\Exception\RuntimeException($previous->getMessage(), previous: $previous);
        }

        // Restore the position without going through `seek()`: a size query is not a seek, and must not
        // reset the cached end-of-data flag.
        $result = @fseek($this->stream, $position);
        if ($result === -1) {
            $error = error_get_last();

            throw new File\Exception\RuntimeException($error['message'] ?? 'unknown error.');
        }

        // @codeCoverageIgnoreEnd

        return $size;
    }

    /**
     * @inheritDoc
     *
     * @codeCoverageIgnore
     */
    #[Override]
    public function lock(
        LockType $type,
        Async\CancellationTokenInterface $cancellation = new Async\NullCancellationToken(),
    ): Lock {
        $wait = Duration::milliseconds(1);
        $max = Duration::milliseconds(100);

        while (true) {
            $cancellation->throwIfCancelled();

            try {
                return $this->tryLock($type);
            } catch (File\Exception\AlreadyLockedException) {
                Async\sleep($wait, $cancellation);
                $wait = Duration::nanoseconds((int) min(
                    $wait->getTotalMilliseconds() * 2,
                    $max->getTotalMilliseconds(),
                ));
            }
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function tryLock(LockType $type): Lock
    {
        if (null === $this->stream) {
            throw new Exception\AlreadyClosedException('Handle has already been closed.');
        }

        $wouldBlock = false;
        $operations = LOCK_NB | ($type === LockType::Exclusive ? LOCK_EX : LOCK_SH);
        $success = @flock($this->stream, $operations, $wouldBlock);
        // @codeCoverageIgnoreStart
        if ($wouldBlock) {
            throw new File\Exception\AlreadyLockedException();
        }

        if (!$success) {
            throw new File\Exception\RuntimeException(sprintf(
                'Could not acquire %s lock for "%s".',
                $type === LockType::Exclusive ? 'exclusive' : 'shared',
                $this->getPath(),
            ));
        }

        return new Lock($type, function (): void {
            /** @var bool $released */
            static $released = false;
            if ($released) {
                return;
            }

            if (null === $this->stream) {
                $released = true;

                // while closing a handle should unlock it, that is not always the case.
                // therefore, we should require users to explicitly release the lock before closing the handle.
                throw new Exception\AlreadyClosedException('Handle was closed before releasing the lock.');
            }

            if (!@flock($this->stream, LOCK_UN)) {
                throw new File\Exception\RuntimeException(sprintf(
                    'Could not release lock for "%s".',
                    $this->getPath(),
                ));
            }

            $released = true;
        });
        // @codeCoverageIgnoreEnd
    }
}
