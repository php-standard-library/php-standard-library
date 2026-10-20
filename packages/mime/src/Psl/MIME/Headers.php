<?php

declare(strict_types=1);

namespace Psl\MIME;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Psl\Default\DefaultInterface;
use Stringable;

use function count;
use function preg_match;
use function preg_split;
use function str_split;
use function strlen;
use function strpbrk;
use function strtolower;

/**
 * Immutable ordered collection of header name-value pairs.
 *
 * Names are compared case-insensitively per RFC 2045. Names must contain
 * printable ASCII without spaces or colons; values must not contain CR, LF, or NUL.
 * Pass unfolded values and let toFoldedString() fold them for transmission.
 *
 * @implements IteratorAggregate<int, array{string, string}>
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2045
 *
 * @api
 */
final readonly class Headers implements Countable, IteratorAggregate, Stringable, DefaultInterface
{
    /**
     * @param list<array{string, lowercase-string, string}> $pairs Triples of (original-name, lowercased-name, value).
     */
    private function __construct(
        private array $pairs,
    ) {}

    /**
     * Create a {@see Headers} instance from an array of name-value pairs.
     *
     * Original header name casing is preserved for serialization, while lookups
     * are performed case-insensitively.
     *
     * @param list<array{string, string}> $pairs Header name-value pairs.
     */
    public static function fromPairs(array $pairs): self
    {
        $headers = [];
        foreach ($pairs as [$header, $value]) {
            self::validate($header, $value);
            $headers[] = [
                $header,
                strtolower($header),
                $value,
            ];
        }

        return new self($headers);
    }

    /**
     * @throws Exception\InvalidArgumentException If a name or value could inject a header.
     */
    private static function validate(string $name, string $value): void
    {
        if (preg_match('/\A[\x21-\x39\x3b-\x7e]+\z/', $name) !== 1) {
            throw Exception\InvalidArgumentException::create(
                'Header names must contain printable ASCII without spaces or colons.',
            );
        }

        if (strpbrk($value, "\r\n\0") !== false) {
            throw Exception\InvalidArgumentException::create('Header values must not contain CR, LF, or NUL.');
        }
    }

    /**
     * Create an empty {@see Headers} instance.
     *
     * The instance is cached as a singleton for efficiency.
     */
    public static function default(): static
    {
        /** @var self|null $instance */
        static $instance = null;

        return $instance ??= new self([]);
    }

    /**
     * Get the first value for a header name (case-insensitive lookup).
     *
     * Returns null if the header is not present.
     */
    public function get(string $name): null|string
    {
        $name = strtolower($name);
        foreach ($this->pairs as [$_, $n, $v]) {
            if ($n === $name) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Check if a header exists (case-insensitive).
     */
    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Get all values for a header name (case-insensitive).
     *
     * @return list<string>
     */
    public function all(string $name): array
    {
        $name = strtolower($name);
        $values = [];
        foreach ($this->pairs as [$_, $n, $v]) {
            if ($n !== $name) {
                continue;
            }

            $values[] = $v;
        }

        return $values;
    }

    /**
     * Return a new Headers instance with the given header appended.
     */
    public function with(string $name, string $value): self
    {
        self::validate($name, $value);

        return new self([...$this->pairs, [$name, strtolower($name), $value]]);
    }

    /**
     * Return a new Headers instance with all occurrences of the given header removed (case-insensitive).
     */
    public function without(string $name): self
    {
        $lower = strtolower($name);
        $filtered = [];
        foreach ($this->pairs as $pair) {
            if ($pair[1] === $lower) {
                continue;
            }

            $filtered[] = $pair;
        }

        return new self($filtered);
    }

    /**
     * Return a new Headers instance with all occurrences of the given header
     * replaced by a single new value. If the header does not exist, it is appended.
     */
    public function replace(string $name, string $value): self
    {
        return $this->without($name)->with($name, $value);
    }

    /**
     * Get all header pairs with their original casing.
     *
     * @return list<array{string, string}> Pairs of (original-name, value).
     */
    public function pairs(): array
    {
        $pairs = [];
        foreach ($this->pairs as [$n, $_, $v]) {
            $pairs[] = [$n, $v];
        }

        return $pairs;
    }

    /**
     * Return the total number of header entries (including duplicate names).
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->pairs);
    }

    /**
     * Iterate over header pairs as (original-name, value) arrays.
     *
     * @return ArrayIterator<int, array{string, string}>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->pairs());
    }

    /**
     * Serialize all headers to a string with CRLF line endings.
     *
     * Each header is rendered as "Name: value\r\n". No line folding is applied;
     * use {@see self::toFoldedString()} for RFC 5322-compliant line folding.
     */
    public function toString(): string
    {
        $result = '';
        foreach ($this->pairs as [$name, $_, $value]) {
            $result .= $name . ': ' . $value . "\r\n";
        }

        return $result;
    }

    /**
     * Serialize headers with line folding per RFC 5322 §2.2.3.
     *
     * Lines exceeding $softLimit are folded at whitespace boundaries by inserting
     * CRLF followed by a single space. The $hardLimit is the absolute maximum line
     * length (998 per RFC 5322); tokens exceeding it are forcibly split.
     *
     * @param int $softLimit Preferred maximum line length before folding (default 78 per RFC 5322 recommendation).
     * @param int $hardLimit Absolute maximum line length (default 998 per RFC 5322 §2.1.1).
     *
     * @throws Exception\InvalidArgumentException If softLimit < 1, hardLimit < 1, or softLimit > hardLimit.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc5322#section-2.2.3
     */
    public function toFoldedString(int $softLimit = 78, int $hardLimit = 998): string
    {
        if ($softLimit < 1 || $hardLimit < 1 || $softLimit > $hardLimit) {
            throw Exception\InvalidArgumentException::create(
                'softLimit must be >= 1, hardLimit must be >= 1, and softLimit must be <= hardLimit',
            );
        }

        $result = '';
        foreach ($this->pairs as [$name, $_, $value]) {
            $result .= self::foldHeader($name, $value, $softLimit, $hardLimit);
        }

        return $result;
    }

    /**
     * Fold a single header line per RFC 5322 SS2.2.3.
     *
     * Splits the header value at whitespace boundaries to keep lines within the
     * soft limit, forcibly splitting tokens that exceed the hard limit.
     */
    private static function foldHeader(string $name, string $value, int $softLimit, int $hardLimit): string
    {
        $line = $name . ': ' . $value;

        if (strlen($line) <= $softLimit) {
            return $line . "\r\n";
        }

        $prefix = $name . ': ';
        $prefixLen = strlen($prefix);
        /** @var list<string> $tokens */
        $tokens = preg_split('/[ \t]+/', $value);

        if ($tokens === ['']) {
            return $line . "\r\n";
        }

        $result = $prefix;
        $currentLen = $prefixLen;
        $first = true;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $tokenLen = strlen($token);
            $needed = $first ? $tokenLen : $tokenLen + 1;

            if (!$first && ($currentLen + $needed) > $softLimit) {
                if (($tokenLen + 1) > $hardLimit) {
                    /** @var int<1, max> $offset */
                    $offset = $hardLimit - 1;
                    $chunks = str_split($token, $offset);
                    foreach ($chunks as $chunk) {
                        $result .= "\r\n " . $chunk;
                    }

                    /** @var int<0, max> $offset */
                    $offset = count($chunks) - 1;
                    $currentLen = 1 + strlen($chunks[$offset]);
                } else {
                    $result .= "\r\n " . $token;
                    $currentLen = 1 + $tokenLen;
                }
            } else {
                if (!$first) {
                    $result .= ' ';
                    $currentLen++;
                }

                $result .= $token;
                $currentLen += $tokenLen;
            }

            $first = false;
        }

        return $result . "\r\n";
    }

    /**
     * Serialize all headers to a string with CRLF line endings.
     *
     * @see self::toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
