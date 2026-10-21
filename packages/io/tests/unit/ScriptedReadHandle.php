<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Throwable;

use function array_shift;

/**
 * A read handle that replays a script of chunks and failures.
 *
 * Each read returns the next string, or throws the next {@see Throwable}.
 * The handle reports EOF once the script is exhausted.
 */
final class ScriptedReadHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    /**
     * @param list<string|Throwable> $script
     */
    public function __construct(
        private array $script,
    ) {}

    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->next();
    }

    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->next();
    }

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->script === [];
    }

    private function next(): string
    {
        if ($this->script === []) {
            return '';
        }

        $item = array_shift($this->script);
        if ($item instanceof Throwable) {
            throw $item;
        }

        return $item;
    }
}
