<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable\Internal;

use Psl\Encoding\QuotedPrintable;

use function strcspn;
use function strlen;
use function substr;

/**
 * Incremental quoted-printable encoder whose concatenated output is identical to
 * {@see QuotedPrintable\encode()} called on the concatenated input with the same parameters.
 *
 * Like {@see QuotedPrintable\encode()}, CRLF, LF and lone CR are all treated as line breaks,
 * each line is encoded with {@see QuotedPrintable\encode_line()}, and lines are joined with
 * the line ending.
 *
 * @internal
 */
final class StreamEncoder
{
    private string $buffer = '';

    private bool $started = false;

    /**
     * @param positive-int $maxLineLength
     * @param non-empty-string $lineEnding
     */
    public function __construct(
        private readonly int $maxLineLength,
        private readonly string $lineEnding,
    ) {}

    /**
     * Feed raw bytes, returning the encoded output for the lines that are complete.
     */
    public function update(string $bytes): string
    {
        $this->buffer .= $bytes;

        return $this->drain(false);
    }

    /**
     * Encode the last (possibly empty) line, and reset the encoder.
     */
    public function finalize(): string
    {
        $output = $this->drain(true);
        if ($this->started || $this->buffer !== '') {
            $output .= $this->encodeLine($this->buffer);
        }

        $this->buffer = '';
        $this->started = false;

        return $output;
    }

    private function drain(bool $final): string
    {
        $output = '';
        while (true) {
            $length = strlen($this->buffer);
            $position = strcspn($this->buffer, "\r\n");
            if ($position === $length) {
                break;
            }

            $terminatorLength = 1;
            if ($this->buffer[$position] === "\r") {
                if (($position + 1) === $length) {
                    // A trailing CR may be the first half of a CRLF: wait for more data.
                    if (!$final) {
                        break;
                    }
                } elseif ($this->buffer[$position + 1] === "\n") {
                    $terminatorLength = 2;
                }
            }

            $output .= $this->encodeLine(substr($this->buffer, 0, $position));
            $this->buffer = substr($this->buffer, $position + $terminatorLength);
        }

        return $output;
    }

    private function encodeLine(string $line): string
    {
        $separator = $this->started ? $this->lineEnding : '';
        $this->started = true;

        return $separator . QuotedPrintable\encode_line($line, $this->maxLineLength, $this->lineEnding);
    }
}
