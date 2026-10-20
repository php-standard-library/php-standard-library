<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable\Internal;

use Psl\Encoding\QuotedPrintable;

use function quoted_printable_decode;
use function strrpos;
use function substr;

/**
 * Incremental quoted-printable decoder whose concatenated output is identical to
 * {@see QuotedPrintable\decode()} called on the concatenated input.
 *
 * Every quoted-printable construct (an "=XY" escape, or a soft line break: "=" followed by optional
 * spaces or tabs and a line break) ends at the latest with the line break that follows it, so the
 * input is decoded up to, and including, its last LF, and the rest is kept until more data arrives.
 * Hard line breaks are preserved as they appear in the input, exactly like {@see QuotedPrintable\decode()}.
 *
 * @internal
 */
final class StreamDecoder
{
    private string $buffer = '';

    /**
     * Feed encoded bytes, returning the decoded output for the lines that are complete.
     */
    public function update(string $bytes): string
    {
        $this->buffer .= $bytes;

        $position = strrpos($this->buffer, "\n");
        if (false === $position) {
            return '';
        }

        $complete = substr($this->buffer, 0, $position + 1);
        $this->buffer = substr($this->buffer, $position + 1);

        return quoted_printable_decode($complete);
    }

    /**
     * Decode any buffered bytes, and reset the decoder.
     */
    public function finalize(): string
    {
        $remaining = $this->buffer;
        $this->buffer = '';

        return $remaining === '' ? '' : quoted_printable_decode($remaining);
    }
}
