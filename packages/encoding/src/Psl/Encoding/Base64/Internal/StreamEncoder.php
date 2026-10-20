<?php

declare(strict_types=1);

namespace Psl\Encoding\Base64\Internal;

use Psl\Encoding\Base64;
use Psl\Encoding\Base64\Variant;

use function strlen;
use function substr;

/**
 * Incremental base64 encoder whose concatenated output is identical to
 * {@see Base64\encode()} called on the concatenated input.
 *
 * Raw bytes are only encoded in groups of 3 bytes (or of {@see Base64\CHUNK_SIZE} bytes, i.e. one
 * full 76 character line, for {@see Variant::Mime}) so that padding is only ever emitted at the end,
 * and MIME line endings are only emitted between lines.
 *
 * @internal
 */
final class StreamEncoder
{
    private string $remainder = '';

    private bool $hasOutput = false;

    public function __construct(
        private readonly Variant $variant,
        private readonly bool $padding,
    ) {}

    /**
     * Feed raw bytes, returning the encoded output that is ready.
     */
    public function update(string $bytes): string
    {
        $data = $this->remainder . $bytes;
        $length = strlen($data);
        $groupSize = $this->variant === Variant::Mime ? Base64\CHUNK_SIZE : 3;
        $usable = $length - ($length % $groupSize);
        if ($usable === 0) {
            $this->remainder = $data;

            return '';
        }

        $this->remainder = substr($data, $usable);

        return $this->encode(substr($data, 0, $usable));
    }

    /**
     * Encode any buffered bytes (with padding, if enabled), and reset the encoder.
     */
    public function finalize(): string
    {
        $output = $this->remainder === '' ? '' : $this->encode($this->remainder);

        $this->remainder = '';
        $this->hasOutput = false;

        return $output;
    }

    private function encode(string $bytes): string
    {
        $separator = $this->hasOutput && $this->variant === Variant::Mime ? Base64\LINE_ENDING : '';
        $this->hasOutput = true;

        return $separator . Base64\encode($bytes, $this->variant, $this->padding);
    }
}
