<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Throwable;

use function strlen;
use function substr;

/**
 * A test handle that returns its content, then throws the given exception on every later read.
 *
 * Simulates a decorator that fails part-way through a stream with an exception of its own,
 * not necessarily an IO one.
 */
final class ThrowingHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private string $content;
    private Throwable $exception;

    public function __construct(string $content, Throwable $exception)
    {
        $this->content = $content;
        $this->exception = $exception;
    }

    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        if ('' === $this->content) {
            throw $this->exception;
        }

        $chunk = null === $maxBytes ? $this->content : substr($this->content, 0, $maxBytes);
        $this->content = substr($this->content, strlen($chunk));

        return $chunk;
    }

    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->tryRead($maxBytes);
    }

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return false;
    }
}
