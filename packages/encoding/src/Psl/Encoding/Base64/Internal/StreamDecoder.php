<?php

declare(strict_types=1);

namespace Psl\Encoding\Base64\Internal;

use Psl\Encoding\Base64;
use Psl\Encoding\Base64\Variant;
use Psl\Encoding\Exception;

use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strspn;
use function substr;

/**
 * Incremental base64 decoder whose concatenated output is identical to
 * {@see Base64\decode()} called on the concatenated input, and which rejects
 * the same inputs.
 *
 * Only {@see Variant::Mime} ignores whitespace, exactly like {@see Base64\decode()} does.
 *
 * @internal
 */
final class StreamDecoder
{
    private string $remainder = '';

    public function __construct(
        private readonly Variant $variant,
        private readonly bool $explicitPadding,
    ) {}

    /**
     * Feed encoded bytes, returning the decoded output that is ready.
     *
     * @throws Exception\RangeException If the encoded data contains characters outside the base64 range.
     * @throws Exception\IncorrectPaddingException If the encoded data has an incorrect padding.
     */
    public function update(string $bytes): string
    {
        if ($this->variant === Variant::Mime) {
            $bytes = str_replace(["\r", "\n", ' ', "\t"], '', $bytes);
        }

        $data = $this->remainder . $bytes;
        $length = strlen($data);
        $usable = $length - ($length % 4);

        // Padding may only end the input, as at most two "=" characters.
        $padding = strpos($data, '=');
        if (false !== $padding) {
            $trailing = $length - $padding;
            if ($trailing > 2 || strspn($data, '=', $padding) !== $trailing) {
                // No data that follows can make this valid: let `decode()` reject it now, as it rejects
                // the whole input, rather than buffering the rest of the input.
                $this->remainder = '';

                return Base64\decode($data, $this->variant, $this->explicitPadding);
            }

            // Keep the group holding the padding buffered until the end, which it must be: it is then
            // decoded, or rejected, along with the rest of the input, as when decoding all of it at once.
            if ($padding < $usable) {
                $usable = $padding - ($padding % 4);
            }
        }

        if ($usable <= 0) {
            $this->remainder = $data;

            return '';
        }

        $this->remainder = substr($data, $usable);

        return Base64\decode(substr($data, 0, $usable), $this->variant, $this->explicitPadding);
    }

    /**
     * Whether a padded group has been received, meaning that only the end of the input may follow.
     */
    public function isAwaitingEnd(): bool
    {
        return str_contains($this->remainder, '=');
    }

    /**
     * Decode any buffered bytes, and reset the decoder.
     *
     * @throws Exception\RangeException If the encoded data contains characters outside the base64 range.
     * @throws Exception\IncorrectPaddingException If the encoded data has an incorrect padding.
     */
    public function finalize(): string
    {
        $remainder = $this->remainder;
        $this->remainder = '';

        return $remainder === '' ? '' : Base64\decode($remainder, $this->variant, $this->explicitPadding);
    }
}
