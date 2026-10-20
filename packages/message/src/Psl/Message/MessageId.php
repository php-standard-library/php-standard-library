<?php

declare(strict_types=1);

namespace Psl\Message;

use Psl\Message\Exception\ParsingException;
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
 * Represents a Message-ID value per RFC 5322 section 3.6.4.
 *
 * Stores the bare identifier (without angle brackets) and provides serialization
 * to the angle-bracket form (`<id@domain>`) required by message headers.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.4
 *
 * @api
 */
final readonly class MessageId implements Stringable
{
    /**
     * The bare ID without angle brackets (e.g. "unique-id@example.com").
     *
     * @var non-empty-string
     */
    public string $id;

    /**
     * @param non-empty-string $id
     *
     * @throws ParsingException If the ID is empty.
     */
    public function __construct(string $id)
    {
        if ($id === '') { // @mago-expect analysis:redundant-comparison,impossible-condition - runtime check
            throw ParsingException::forInvalidMessageId($id);
        }

        $this->id = $id;
    }

    /**
     * Generate a unique Message-ID.
     *
     * @throws EntropyException If the system cannot generate secure random bytes.
     */
    public static function generate(string $domain = 'php-standard-library.dev'): self
    {
        try {
            $unique = bin2hex(random_bytes(16));
        } catch (RandomException $e) {
            throw EntropyException::forInsufficientEntropy($e);
        }

        return new self($unique . '@' . $domain);
    }

    /**
     * Parse a Message-ID value.
     *
     * Accepts:
     * - `<id@domain>` (angle-bracket form, standard in headers)
     * - `id@domain` (bare form)
     *
     * @throws ParsingException If the input is empty or malformed.
     */
    public static function parse(string $input): self
    {
        $input = trim($input, " \t\r\n");

        if ($input === '') {
            throw ParsingException::forInvalidMessageId($input);
        }

        if (str_starts_with($input, '<') && str_ends_with($input, '>')) {
            /** @var non-negative-int $innerLen */
            $innerLen = strlen($input) - 2;
            $inner = substr($input, 1, $innerLen);
            if ($inner === '') {
                throw ParsingException::forInvalidMessageId($input);
            }

            return new self($inner);
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
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
