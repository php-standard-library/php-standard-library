<?php

declare(strict_types=1);

namespace Psl\MIME;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Psl\Default\DefaultInterface;
use Psl\MIME\Exception\InvalidMediaTypeComponentException;
use Psl\MIME\Internal\MediaTypeParser;
use Stringable;

use function count;
use function ord;
use function str_contains;
use function strlen;
use function strpbrk;
use function strtolower;
use function strtr;

/**
 * Immutable ordered map of parameter name→value pairs.
 *
 * Names are case-insensitive. Handles RFC 2231 encoding/decoding transparently.
 *
 * @implements IteratorAggregate<int, array{string, string}>
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2045#section-5.1
 * @link https://datatracker.ietf.org/doc/html/rfc2231
 *
 * @api
 */
final readonly class Parameters implements Countable, IteratorAggregate, Stringable, DefaultInterface
{
    /**
     * @param list<array{non-empty-lowercase-string, string}> $pairs Ordered name-value pairs (names already lowercased).
     */
    private function __construct(
        private array $pairs,
    ) {
        foreach ($pairs as [$_, $value]) {
            if (strpbrk($value, "\r\n\0") !== false) {
                throw Exception\InvalidArgumentException::create('Parameter values must not contain CR, LF, or NUL.');
            }
        }
    }

    /**
     * Parse a parameter string (the portion after the semicolon in a media type).
     *
     * Handles quoted-string values and RFC 2231 continuations/encoding.
     *
     * @param string $input The raw parameter string (e.g. '; charset=utf-8; boundary="---"').
     *
     * @throws Exception\ParsingException If the input is malformed.
     */
    public static function parse(string $input): self
    {
        try {
            return new self(MediaTypeParser::parseParameters($input));
        } catch (Exception\InvalidArgumentException) {
            throw Exception\ParameterParsingException::forInvalidParameter($input);
        }
    }

    /**
     * Create a {@see Parameters} instance from an array of name-value pairs.
     *
     * Names are lowercased and validated as RFC 2045 tokens. Duplicate names are preserved
     * in insertion order.
     *
     * @param list<array{string, string}> $pairs Name-value pairs to include.
     *
     * @throws InvalidMediaTypeComponentException If a parameter name is empty or contains non-token characters.
     */
    public static function fromPairs(array $pairs): self
    {
        $normalized = [];
        foreach ($pairs as [$name, $value]) {
            $name = strtolower($name);
            self::validateParameterName($name);

            $normalized[] = [$name, $value];
        }

        return new self($normalized);
    }

    /**
     * Create an empty Parameters instance.
     */
    public static function default(): static
    {
        /** @var self|null $instance */
        static $instance = null;

        return $instance ??= new self([]);
    }

    /**
     * Get the value of a parameter by name (case-insensitive).
     */
    public function get(string $name): null|string
    {
        $name = strtolower($name);
        foreach ($this->pairs as [$n, $v]) {
            if ($n === $name) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Check if a parameter exists (case-insensitive).
     *
     * @psalm-assert-if-true non-empty-lowercase-string $name
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Get all parameter pairs.
     *
     * @return list<array{string, string}>
     */
    public function all(): array
    {
        return $this->pairs;
    }

    /**
     * Return the number of parameters in this collection.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->pairs);
    }

    /**
     * Iterate over the parameters as name-value pairs.
     *
     * @return ArrayIterator<int, array{string, string}>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->pairs);
    }

    /**
     * Serialize parameters to the format used in media type strings.
     *
     * Each parameter is prefixed with "; " (e.g. "; charset=utf-8"). Values requiring
     * quoting are enclosed in double quotes with backslash escaping.
     * Returns an empty string if there are no parameters.
     */
    public function toString(): string
    {
        if ($this->pairs === []) {
            return '';
        }

        $result = '';
        foreach ($this->pairs as [$name, $value]) {
            $result .= '; ' . $name . '=' . self::serializeValue($value);
        }

        return $result;
    }

    /**
     * Serialize parameters to the format used in media type strings.
     *
     * @see self::toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Serialize a parameter value, quoting if necessary.
     *
     * Token characters don't need quoting. Everything else gets quoted-string treatment.
     */
    private static function serializeValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (self::isToken($value)) {
            return $value;
        }

        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
        ]);

        return '"' . $escaped . '"';
    }

    /**
     * Check whether a string consists entirely of RFC 2045 token characters.
     *
     * Token characters are printable ASCII characters excluding tspecials and whitespace.
     */
    private static function isToken(string $value): bool
    {
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ord = ord($value[$i]);

            if ($ord <= 32 || $ord >= 127) {
                return false;
            }

            if (str_contains('()<>@,;:\\"/?=[]', $value[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that a parameter name contains only token characters.
     *
     * @throws InvalidMediaTypeComponentException
     *
     * @assert =non-empty-lowercase-string $name
     */
    private static function validateParameterName(string $name): void
    {
        if ($name === '') {
            throw InvalidMediaTypeComponentException::forParameterName($name);
        }

        for ($i = 0, $len = strlen($name); $i < $len; $i++) {
            $ord = ord($name[$i]);

            if ($ord <= 32 || $ord >= 127) {
                throw InvalidMediaTypeComponentException::forParameterName($name);
            }

            if (str_contains('()<>@,;:\\"/?=[]', $name[$i])) {
                throw InvalidMediaTypeComponentException::forParameterName($name);
            }
        }
    }
}
