<?php

declare(strict_types=1);

namespace Psl\Message\Address;

use Psl\Encoding\EncodedWord;
use Psl\Message\Exception\InvalidArgumentException;
use Psl\Message\Exception\ParsingException;
use Stringable;

use function array_map;
use function explode;
use function implode;
use function str_ends_with;
use function strpbrk;
use function strpos;
use function strrpos;
use function substr;
use function trim;

/**
 * Represents a named group of mailboxes per RFC 5322 section 3.4.
 *
 * Models the `display-name : [mailbox-list] ;` syntax used in address
 * header fields. A group may contain zero or more {@see Mailbox} entries.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.4
 *
 * @api
 */
final readonly class Group implements Stringable
{
    /**
     * The display name of the group.
     */
    public string $displayName;

    /**
     * The mailboxes in this group.
     *
     * @var list<Mailbox>
     */
    public array $mailboxes;

    /**
     * @param list<Mailbox> $mailboxes
     */
    public function __construct(string $displayName, array $mailboxes = [])
    {
        if (strpbrk($displayName, "\r\n\0") !== false) {
            throw InvalidArgumentException::forInvalidHeaderValue();
        }

        $this->displayName = $displayName;
        $this->mailboxes = $mailboxes;
    }

    /**
     * Parse a group from a string.
     *
     * Expects `display-name : [mailbox-list] ;` format.
     * With $lenient, skip malformed mailboxes in received headers.
     *
     * @throws ParsingException If the input is not a valid group.
     */
    public static function parse(string $input, bool $lenient = false): self
    {
        $input = trim($input, " \t\r\n");

        $colonPos = strpos($input, ':');
        if ($colonPos === false || $colonPos === 0) {
            throw ParsingException::forInvalidGroup($input);
        }

        if (!str_ends_with(trim($input, " \t\r\n"), ';')) {
            throw ParsingException::forInvalidGroup($input);
        }

        $displayName = EncodedWord\decode(trim(substr($input, 0, $colonPos), " \t\r\n"));

        if (strpbrk($displayName, "\r\n\0") !== false) {
            throw ParsingException::forInvalidGroup($input);
        }

        /** @var int $semicolonPos - guaranteed non-null because we checked ends_with(';') above */
        $semicolonPos = strrpos($input, ';');
        /** @var non-negative-int $length */
        $length = $semicolonPos - $colonPos - 1;
        $mailboxListStr = trim(substr($input, $colonPos + 1, $length), " \t\r\n");

        if ($mailboxListStr === '') {
            return new self($displayName);
        }

        $parts = explode(',', $mailboxListStr);
        $mailboxes = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\r\n");
            if ($part === '') {
                continue;
            }

            try {
                $mailboxes[] = Mailbox::parse($part);
            } catch (ParsingException $exception) {
                if (!$lenient) {
                    throw $exception;
                }
            }
        }

        return new self($displayName, $mailboxes);
    }

    /**
     * Serialize the group for use in headers.
     */
    public function toString(): string
    {
        $encoded = EncodedWord\encode($this->displayName);

        if ($this->mailboxes === []) {
            return $encoded . ':;';
        }

        $mailboxStrings = array_map(static fn(Mailbox $m): string => $m->toString(), $this->mailboxes);

        return $encoded . ': ' . implode(', ', $mailboxStrings) . ';';
    }

    /**
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
