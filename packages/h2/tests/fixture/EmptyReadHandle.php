<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Fixture;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function array_shift;
use function array_unshift;
use function strlen;
use function substr;

/**
 * A transport whose reads return scripted chunks, some of them empty, before the end of its data.
 *
 * An empty chunk is a read that returns nothing while the connection stays open, as a TLS stream does when its
 * socket wakes for a record that carries no application data or for a record that has only partly arrived. The
 * end of the data is reached only once every chunk has been read.
 */
final class EmptyReadHandle implements IO\ReadHandleInterface, IO\WriteHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    /**
     * Whether a read found no chunk left.
     */
    private bool $eof = false;

    /**
     * Every byte written to the transport.
     */
    public private(set) string $written = '';

    /**
     * How many reads returned an empty chunk before the end of the data.
     */
    public private(set) int $emptyReads = 0;

    /**
     * @param list<string> $chunks The chunks the reads return, in order; an empty chunk is an empty read.
     */
    public function __construct(
        private array $chunks,
    ) {}

    public function reachedEndOfDataSource(): bool
    {
        return $this->eof;
    }

    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->read($maxBytes);
    }

    /**
     * Return up to `$maxBytes` bytes of the next chunk; what is left of it is returned by the next read.
     */
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        if ([] === $this->chunks) {
            $this->eof = true;

            return '';
        }

        $chunk = array_shift($this->chunks);
        if ('' === $chunk) {
            $this->emptyReads++;

            return '';
        }

        if (null !== $maxBytes && strlen($chunk) > $maxBytes) {
            array_unshift($this->chunks, substr($chunk, $maxBytes));
            $chunk = substr($chunk, 0, $maxBytes);
        }

        return $chunk;
    }

    public function tryWrite(string $bytes): int
    {
        $this->written .= $bytes;

        return strlen($bytes);
    }

    public function write(string $bytes, CancellationTokenInterface $cancellation = new NullCancellationToken()): int
    {
        return $this->tryWrite($bytes);
    }

    public function writeAll(
        string $bytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): void {
        $this->written .= $bytes;
    }
}
