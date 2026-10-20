<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Fixture;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function min;
use function strlen;
use function substr;

final class ChunkedHandle implements IO\ReadHandleInterface, IO\WriteHandleInterface
{
    public private(set) int $readPos = 0;

    private bool $eof = false;

    public private(set) string $written = '';

    /** @var list<null|positive-int> */
    public private(set) array $readLimits = [];

    /**
     * @param positive-int $chunkSize
     */
    public function __construct(
        private readonly string $data,
        private readonly int $chunkSize = 1,
    ) {}

    public function reachedEndOfDataSource(): bool
    {
        return $this->eof;
    }

    public function tryRead(null|int $maxBytes = null): string
    {
        return $this->read($maxBytes);
    }

    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $this->readLimits[] = $maxBytes;
        if ($this->readPos >= strlen($this->data)) {
            $this->eof = true;

            return '';
        }

        /** @var int<0, max> $readPos */
        $readPos = $this->readPos;
        $chunk = substr($this->data, $readPos, min($maxBytes ?? $this->chunkSize, $this->chunkSize));
        $this->readPos += strlen($chunk);

        return $chunk;
    }

    public function readAll(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        /** @var int<0, max> $readPos */
        $readPos = $this->readPos;
        $remaining = substr($this->data, $readPos);
        $this->readPos = strlen($this->data);
        $this->eof = true;

        return $remaining;
    }

    public function readFixedSize(
        int $size,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $result = '';
        while (strlen($result) < $size) {
            $chunk = $this->read();
            if ($chunk === '') {
                break;
            }

            $result .= $chunk;
        }

        return $result;
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
