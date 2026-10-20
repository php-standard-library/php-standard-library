<?php

declare(strict_types=1);

namespace Psl\MIME;

use Psl\DateTime;
use Psl\DateTime\DateTimeInterface;
use Psl\MIME\Exception\ContentDispositionParsingException;
use Psl\MIME\Internal\MediaTypeParser;
use Stringable;

use function basename;
use function ctype_digit;
use function preg_match;
use function str_contains;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Content-Disposition header value per RFC 2183.
 *
 * Represents disposition type (inline/attachment) and parameters
 * (filename, creation-date, modification-date, read-date, size).
 *
 * @link https://datatracker.ietf.org/doc/html/rfc2183
 *
 * @api
 */
final readonly class ContentDisposition implements Stringable
{
    /**
     * The disposition type (e.g. "inline", "attachment").
     *
     * @var non-empty-lowercase-string
     */
    public string $type;

    /**
     * Parameters associated with this disposition (e.g. filename, creation-date, size).
     */
    public Parameters $parameters;

    /**
     * Construct a Content-Disposition with the given type and optional parameters.
     *
     * The type is lowercased and validated as an RFC 2045 token (no whitespace,
     * no special characters).
     *
     * @param string $type The disposition type (e.g. "inline", "attachment").
     *
     * @throws ContentDispositionParsingException If the type is empty or contains invalid characters.
     */
    public function __construct(string $type, null|Parameters $parameters = null)
    {
        $type = strtolower($type);

        if ($type === '') {
            throw ContentDispositionParsingException::forInvalidContentDisposition($type);
        }

        if (preg_match('/[\x00-\x20\x7F-\xFF()<>@,;:\x5C\x22\/?\x3D\x5B\x5D]/', $type)) {
            throw ContentDispositionParsingException::forInvalidContentDisposition($type);
        }

        $this->type = $type;
        $this->parameters = $parameters ?? Parameters::default();
    }

    /**
     * Create an "inline" Content-Disposition, optionally with a filename parameter.
     *
     * Inline disposition indicates the content should be displayed as part of the message.
     *
     * @throws Exception\InvalidMediaTypeComponentException If the "filename" parameter name fails validation.
     */
    public static function inline(null|string $filename = null): self
    {
        return self::create('inline', $filename);
    }

    /**
     * Create an "attachment" Content-Disposition, optionally with a filename parameter.
     *
     * Attachment disposition indicates the content should be downloaded separately.
     *
     * @throws Exception\InvalidMediaTypeComponentException If the "filename" parameter name fails validation.
     */
    public static function attachment(null|string $filename = null): self
    {
        return self::create('attachment', $filename);
    }

    /**
     * Parse a Content-Disposition header value string.
     *
     * Accepts a disposition type optionally followed by semicolon-separated parameters
     * (e.g. 'attachment; filename="report.pdf"').
     *
     * @param string $input The raw Content-Disposition header value.
     *
     * @throws ContentDispositionParsingException If the input is malformed or the disposition type is invalid.
     * @throws Exception\InvalidMediaTypeComponentException If a parameter name is invalid.
     */
    public static function parse(string $input): self
    {
        $input = trim($input, " \t\r\n");

        $semicolonPos = strpos($input, ';');
        if ($semicolonPos === false) {
            return new self($input);
        }

        $type = trim(substr($input, 0, $semicolonPos), " \t\r\n");
        $paramString = substr($input, $semicolonPos + 1);
        $params = MediaTypeParser::parseParameters($paramString);

        return new self($type, Parameters::fromPairs($params));
    }

    /**
     * Get a safe filename, stripped of directory components and path traversal.
     *
     * Returns only the basename. Returns null if the parameter is absent,
     * or if the sanitized result is empty or consists only of dots.
     */
    public function filename(): null|string
    {
        $raw = $this->parameters->get('filename');
        if ($raw === null) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $raw));
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\x00")) {
            return null;
        }

        return $name;
    }

    /**
     * Get the raw filename parameter value as received in the header.
     *
     * This value is NOT sanitized and may contain path traversal sequences.
     * Do not use this for file writes without your own validation.
     */
    public function unsafeFilename(): null|string
    {
        return $this->parameters->get('filename');
    }

    /**
     * Get the "creation-date" parameter as a {@see DateTimeInterface}, or null if absent or unparseable.
     *
     * Supports RFC 5322 date format and ISO 8601 format.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2183#section-2.4
     */
    public function creationDate(): null|DateTimeInterface
    {
        return self::parseDate($this->parameters->get('creation-date'));
    }

    /**
     * Get the "modification-date" parameter as a {@see DateTimeInterface}, or null if absent or unparseable.
     *
     * Supports RFC 5322 date format and ISO 8601 format.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2183#section-2.5
     */
    public function modificationDate(): null|DateTimeInterface
    {
        return self::parseDate($this->parameters->get('modification-date'));
    }

    /**
     * Get the "read-date" parameter as a {@see DateTimeInterface}, or null if absent or unparseable.
     *
     * Supports RFC 5322 date format and ISO 8601 format.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2183#section-2.6
     */
    public function readDate(): null|DateTimeInterface
    {
        return self::parseDate($this->parameters->get('read-date'));
    }

    /**
     * Get the "size" parameter as an integer number of bytes, or null if absent or non-numeric.
     *
     * @return int<0, max>|null
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2183#section-2.7
     */
    public function size(): null|int
    {
        $size = $this->parameters->get('size');
        if ($size === null || !ctype_digit($size)) {
            return null;
        }

        /** @var int<0, max> */
        return (int) $size;
    }

    /**
     * Serialize the Content-Disposition to its header value representation.
     *
     * Example: 'attachment; filename="report.pdf"'
     */
    public function toString(): string
    {
        return $this->type . $this->parameters->toString();
    }

    /**
     * Serialize the Content-Disposition to its header value representation.
     *
     * @see self::toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Create a Content-Disposition with the given type and optional filename parameter.
     */
    private static function create(string $type, null|string $filename): self
    {
        if ($filename === null) {
            return new self($type);
        }

        return new self($type, Parameters::fromPairs([['filename', $filename]]));
    }

    /**
     * Parse a date string from a Content-Disposition parameter value.
     *
     * Attempts RFC 5322 date format first, then ISO 8601. Returns null if
     * the value is absent or cannot be parsed by either format.
     */
    private static function parseDate(null|string $value): null|DateTimeInterface
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t\"");

        $patterns = [
            'EEE, dd MMM yyyy HH:mm:ss xx',
            "yyyy-MM-dd'T'HH:mm:ssxxx",
        ];

        foreach ($patterns as $pattern) {
            try {
                return DateTime\DateTime::parse($value, $pattern);
            } catch (DateTime\Exception\RuntimeException) {
                continue;
            }
        }

        return null;
    }
}
