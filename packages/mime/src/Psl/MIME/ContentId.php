<?php

declare(strict_types=1);

namespace Psl\MIME;

use Psl\MIME\Exception\ContentIdParsingException;
use Psl\MIME\Exception\EntropyException;
use Random\RandomException;
use Stringable;

use function bin2hex;
use function random_bytes;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Content-ID value per RFC 2392.
 *
 * Represents a Content-ID for referencing MIME parts. Supports the `cid:` URI scheme.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2392
 *
 * @api
 */
final readonly class ContentId implements Stringable
{
    /**
     * The bare ID without angle brackets (e.g. "part1@example.com").
     *
     * @var non-empty-string
     */
    public string $id;

    /**
     * @throws ContentIdParsingException If the ID is empty.
     *
     * @psalm-assert =non-empty-string $id
     */
    public function __construct(string $id)
    {
        if ($id === '') {
            throw ContentIdParsingException::forInvalidContentId($id);
        }

        $this->id = $id;
    }

    /**
     * Generate a new globally unique Content-ID using cryptographically secure random bytes.
     *
     * The generated ID has the form "{32-hex-chars}@{domain}".
     *
     * @param string $domain The domain portion of the Content-ID (after the "@").
     *
     * @throws EntropyException If the system cannot provide sufficient random bytes.
     */
    public static function generate(string $domain = 'php-standard-library.dev'): self
    {
        try {
            $unique = bin2hex(random_bytes(16));
            // @codeCoverageIgnoreStart
        } catch (RandomException $e) {
            throw EntropyException::forInsufficientEntropy($e);
        }

        // @codeCoverageIgnoreEnd

        return new self($unique . '@' . $domain);
    }

    /**
     * Parse a Content-ID value.
     *
     * Accepts:
     * - `<id@domain>` (angle-bracket form, standard in headers)
     * - `id@domain` (bare form)
     * - `cid:id@domain` (URI form per RFC 2392)
     *
     * @throws ContentIdParsingException If the input is empty or malformed.
     *
     * @psalm-assert =non-empty-string $input
     */
    public static function parse(string $input): self
    {
        $input = trim($input, " \t\r\n");

        if (str_starts_with($input, 'cid:')) {
            return new self(substr($input, 4));
        }

        if (str_starts_with($input, '<') && str_ends_with($input, '>')) {
            /** @var non-negative-int $innerLen */
            $innerLen = strlen($input) - 2;

            return new self(substr($input, 1, $innerLen));
        }

        return new self($input);
    }

    /**
     * Serialize as angle-bracket form for use in headers.
     *
     * @return non-empty-string
     */
    public function toString(): string
    {
        return '<' . $this->id . '>';
    }

    /**
     * Serialize as a `cid:` URI per RFC 2392.
     *
     * @return non-empty-string
     */
    public function toCidUri(): string
    {
        return 'cid:' . $this->id;
    }

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
