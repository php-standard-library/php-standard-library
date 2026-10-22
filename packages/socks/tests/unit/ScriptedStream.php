<?php

declare(strict_types=1);

namespace Psl\Socks\Tests\Unit;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function strlen;
use function substr;

/**
 * A stream that plays a proxy's scripted replies, and records every byte written to it, one byte per write.
 */
final class ScriptedStream implements IO\ReadHandleInterface, IO\WriteHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;
    use IO\WriteHandleConvenienceMethodsTrait;

    /**
     * Every byte written to the stream so far.
     */
    public string $written = '';

    /**
     * The replies that the stream has not been read for yet.
     */
    private IO\MemoryHandle $input;

    /**
     * Create a stream that replies with the given bytes.
     */
    public function __construct(string $replies)
    {
        $this->input = new IO\MemoryHandle($replies);
    }

    /**
     * Whether every reply has been read.
     */
    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->input->reachedEndOfDataSource();
    }

    /**
     * Read the next replies, without waiting.
     *
     * @param ?positive-int $maxBytes
     */
    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->input->tryRead($maxBytes);
    }

    /**
     * Read the next replies.
     *
     * @param ?positive-int $maxBytes
     */
    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        return $this->input->read($maxBytes, $cancellation);
    }

    /**
     * Record the first byte, and report it as the only byte written.
     *
     * @return int<0, max>
     */
    #[Override]
    public function tryWrite(string $bytes): int
    {
        $byte = substr($bytes, 0, 1);
        $this->written .= $byte;

        return strlen($byte);
    }

    /**
     * Record the first byte, and report it as the only byte written.
     *
     * @return int<0, max>
     */
    #[Override]
    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        $cancellation->throwIfCancelled();

        return $this->tryWrite($bytes);
    }
}
