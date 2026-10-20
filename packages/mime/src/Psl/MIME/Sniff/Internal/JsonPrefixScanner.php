<?php

declare(strict_types=1);

namespace Psl\MIME\Sniff\Internal;

use function array_pop;
use function count;
use function hexdec;
use function ord;
use function preg_match;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strcspn;
use function strlen;
use function strspn;
use function substr;

/**
 * Checks whether a byte string is a JSON text (RFC 8259), or a prefix of one.
 *
 * Content sniffing only sees the first bytes of a resource, so a JSON document larger than the
 * sniff window arrives truncated and can never pass a full validation. This scanner walks the
 * JSON grammar instead and accepts the input as long as every byte is consistent with it:
 * containers, strings, escapes, numbers and literals may be left open at the end, and so may a
 * trailing multi-byte UTF-8 sequence. Like `json_validate()`, it rejects invalid UTF-8 and unpaired
 * UTF-16 surrogate escapes.
 *
 * Each token method returns null when the token is complete, true when the input ends inside
 * the token (a valid prefix), and false on a grammar violation.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc8259
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 *
 * @internal
 */
final class JsonPrefixScanner
{
    private const int EXPECT_VALUE = 0;
    private const int EXPECT_VALUE_OR_CLOSE = 1;
    private const int EXPECT_KEY_OR_CLOSE = 2;
    private const int EXPECT_KEY = 3;
    private const int EXPECT_COLON = 4;
    private const int EXPECT_SEPARATOR = 5;
    private const int EXPECT_NOTHING = 6;

    private const string WHITESPACE = " \t\n\r";
    private const string DIGITS = '0123456789';
    private const string HEX_DIGITS = '0123456789abcdefABCDEF';

    /**
     * Bytes that end a run of plain string characters: the quote, the backslash and the
     * control characters U+0000 to U+001F, which must be escaped.
     */
    private const string STRING_STOP =
        "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
            . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

    private readonly int $length;

    private int $position = 0;

    private int $expect = self::EXPECT_VALUE;

    /**
     * The open containers, innermost last.
     *
     * @var list<string>
     */
    private array $stack = [];

    private function __construct(
        private readonly string $input,
    ) {
        $this->length = strlen($input);
    }

    /**
     * Whether the input is a JSON text, or a prefix of one.
     */
    public static function isValidPrefix(string $input): bool
    {
        if (!self::isUtf8Prefix($input)) {
            return false;
        }

        return new self($input)->scan();
    }

    private function scan(): bool
    {
        while (true) {
            $this->position += strspn($this->input, self::WHITESPACE, $this->position);
            if ($this->position >= $this->length) {
                return true;
            }

            $char = $this->input[$this->position];
            $result = match ($this->expect) {
                self::EXPECT_NOTHING => false,
                self::EXPECT_COLON => $this->colon($char),
                self::EXPECT_SEPARATOR => $char === ',' ? $this->comma() : $this->close($char),
                self::EXPECT_KEY_OR_CLOSE => $char === '}' ? $this->close($char) : $this->key($char),
                self::EXPECT_KEY => $this->key($char),
                self::EXPECT_VALUE_OR_CLOSE => $char === ']' ? $this->close($char) : $this->value($char),
                default => $this->value($char),
            };

            if ($result !== null) {
                return $result;
            }
        }
    }

    private function colon(string $char): null|bool
    {
        if ($char !== ':') {
            return false;
        }

        $this->position++;
        $this->expect = self::EXPECT_VALUE;

        return null;
    }

    private function comma(): null
    {
        $this->position++;
        $this->expect = $this->stack[count($this->stack) - 1] === '{' ? self::EXPECT_KEY : self::EXPECT_VALUE;

        return null;
    }

    private function close(string $char): null|bool
    {
        $open = match ($char) {
            ']' => '[',
            '}' => '{',
            default => null,
        };

        if ($open === null || array_pop($this->stack) !== $open) {
            return false;
        }

        $this->position++;
        $this->completeValue();

        return null;
    }

    private function key(string $char): null|bool
    {
        if ($char !== '"') {
            return false;
        }

        $result = $this->string();
        if ($result === null) {
            $this->expect = self::EXPECT_COLON;
        }

        return $result;
    }

    private function value(string $char): null|bool
    {
        if ($char === '{' || $char === '[') {
            $this->stack[] = $char;
            $this->position++;
            $this->expect = $char === '{' ? self::EXPECT_KEY_OR_CLOSE : self::EXPECT_VALUE_OR_CLOSE;

            return null;
        }

        $result = match (true) {
            $char === '"' => $this->string(),
            $char === '-' || str_contains(self::DIGITS, $char) => $this->number(),
            default => $this->literal($char),
        };

        if ($result === null) {
            $this->completeValue();
        }

        return $result;
    }

    private function completeValue(): void
    {
        $this->expect = $this->stack === [] ? self::EXPECT_NOTHING : self::EXPECT_SEPARATOR;
    }

    /**
     * Scan a string starting at its opening quote.
     */
    private function string(): null|bool
    {
        $this->position++;
        while (true) {
            $this->position += strcspn($this->input, self::STRING_STOP, $this->position);
            if ($this->position >= $this->length) {
                return true;
            }

            $char = $this->input[$this->position];
            $this->position++;
            if ($char === '"') {
                return null;
            }

            if ($char !== '\\') {
                // Unescaped control character.
                return false;
            }

            $result = $this->escape();
            if ($result !== null) {
                return $result;
            }
        }
    }

    /**
     * Scan an escape sequence starting right after its backslash.
     *
     * Like `json_validate()`, a "\u" escape of a UTF-16 surrogate must be a high surrogate
     * immediately followed by a "\u" escape of a low surrogate.
     */
    private function escape(): null|bool
    {
        if ($this->position >= $this->length) {
            return true;
        }

        $char = $this->input[$this->position];
        if ($char !== 'u') {
            $this->position++;

            return str_contains('"\\/bfnrt', $char) ? null : false;
        }

        $codeUnit = $this->unicodeEscape();
        if ($codeUnit === true) {
            return true;
        }

        if ($codeUnit === false || $codeUnit >= 0xDC00 && $codeUnit <= 0xDFFF) {
            return false;
        }

        if ($codeUnit < 0xD800 || $codeUnit > 0xDBFF) {
            return null;
        }

        // A high surrogate: the low surrogate escape must follow.
        $backslash = substr($this->input, $this->position, 2);
        if (!str_starts_with('\\u', $backslash)) {
            return false;
        }

        if ($backslash !== '\\u') {
            return true;
        }

        $this->position++;
        $codeUnit = $this->unicodeEscape();
        if ($codeUnit === true) {
            return true;
        }

        return $codeUnit !== false && $codeUnit >= 0xDC00 && $codeUnit <= 0xDFFF ? null : false;
    }

    /**
     * Scan the "u" and four hex digits of a unicode escape, returning the UTF-16 code unit, true
     * when the input ends inside the escape, or false on a grammar violation.
     */
    private function unicodeEscape(): int|bool
    {
        $hex = substr($this->input, $this->position + 1, 4);
        $hexLength = strlen($hex);
        if (strspn($hex, self::HEX_DIGITS) !== $hexLength) {
            return false;
        }

        if ($hexLength < 4) {
            return true;
        }

        $this->position += 5;

        return (int) hexdec($hex);
    }

    private function number(): null|bool
    {
        $this->consume('-');

        $start = $this->position;
        $integerDigits = $this->digits();
        if ($integerDigits === 0) {
            return $this->atEnd();
        }

        if ($integerDigits > 1 && $this->input[$start] === '0') {
            // Leading zero.
            return false;
        }

        if ($this->consume('.') && $this->digits() === 0) {
            return $this->atEnd();
        }

        if ($this->consume('e') || $this->consume('E')) {
            if (!$this->consume('+')) {
                $this->consume('-');
            }

            if ($this->digits() === 0) {
                return $this->atEnd();
            }
        }

        return null;
    }

    private function literal(string $char): null|bool
    {
        $literal = match ($char) {
            't' => 'true',
            'f' => 'false',
            'n' => 'null',
            default => null,
        };

        if ($literal === null) {
            return false;
        }

        $available = substr($this->input, $this->position, strlen($literal));
        if (!str_starts_with($literal, $available)) {
            return false;
        }

        $this->position += strlen($available);

        return strlen($available) < strlen($literal) ? true : null;
    }

    /**
     * Consume a run of decimal digits, returning how many were consumed.
     */
    private function digits(): int
    {
        $count = strspn($this->input, self::DIGITS, $this->position);
        $this->position += $count;

        return $count;
    }

    private function consume(string $char): bool
    {
        if ($this->position < $this->length && $this->input[$this->position] === $char) {
            $this->position++;

            return true;
        }

        return false;
    }

    private function atEnd(): bool
    {
        return $this->position >= $this->length;
    }

    /**
     * Whether the input is valid UTF-8, or valid UTF-8 followed by the start of a multi-byte
     * sequence that is cut off at its end.
     */
    private static function isUtf8Prefix(string $input): bool
    {
        $length = strlen($input);
        for ($back = 1; $back <= 3 && $back <= $length; $back++) {
            $byte = ord($input[$length - $back]);
            if ($byte < 0x80) {
                break;
            }

            if ($byte < 0xC0) {
                // Continuation byte; keep looking for the lead byte.
                continue;
            }

            $sequenceLength = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                default => 2,
            };

            $missing = $sequenceLength - $back;
            if ($missing > 0) {
                // The cut-off sequence is a valid prefix only if some continuation completes it.
                // Each lead byte restricts its second byte to a range that contains 0x80 or 0xBF,
                // and later bytes accept both, so trying these two completions is exhaustive.
                $tail = substr($input, $length - $back);
                if (
                    preg_match('//u', $tail . str_repeat("\x80", $missing)) !== 1
                    && preg_match('//u', $tail . str_repeat("\xBF", $missing)) !== 1
                ) {
                    return false;
                }

                $input = substr($input, 0, $length - $back);
            }

            break;
        }

        return preg_match('//u', $input) === 1;
    }
}
