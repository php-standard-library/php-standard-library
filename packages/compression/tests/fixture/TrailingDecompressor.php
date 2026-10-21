<?php

declare(strict_types=1);

namespace Psl\Compression\Tests\Fixture;

use Override;
use Psl\Compression\DecompressorInterface;

/**
 * Passes data through unchanged and ends every stream with the trailer `!!`.
 */
final class TrailingDecompressor implements DecompressorInterface
{
    /**
     * How many times {@see finish()} was called.
     */
    public int $finished = 0;

    #[Override]
    public function push(string $data): string
    {
        return $data;
    }

    #[Override]
    public function finish(): string
    {
        $this->finished++;

        return '!!';
    }
}
