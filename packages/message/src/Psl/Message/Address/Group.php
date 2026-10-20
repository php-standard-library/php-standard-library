<?php

declare(strict_types=1);

namespace Psl\Message\Address;

use Psl\Encoding\EncodedWord;
use Psl\Message\Exception\InvalidArgumentException;
use Psl\Message\Exception\ParsingException;
use Stringable;

use function array_map;
use function implode;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strrpos;
use function strtr;
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

        $colonPos = null;
        $inQuote = false;
        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            if ($input[$i] === '\\' && $inQuote) {
                $i++;
                continue;
            }

            if ($input[$i] === '"') {
                $inQuote = !$inQuote;
            } elseif ($input[$i] === ':' && !$inQuote) {
                $colonPos = $i;
                break;
            }
        }

        if ($colonPos === null || $colonPos === 0) {
            throw ParsingException::forInvalidGroup($input);
        }

        if (!str_ends_with(trim($input, " \t\r\n"), ';')) {
            throw ParsingException::forInvalidGroup($input);
        }

        $displayName = trim(substr($input, 0, $colonPos), " \t\r\n");
        if (str_starts_with($displayName, '"') && str_ends_with($displayName, '"') && strlen($displayName) >= 2) {
            $displayName = preg_replace('/\\\\([\x09\x20-\x7e])/', '$1', substr($displayName, 1, -1)) ?? '';
        } else {
            $displayName = EncodedWord\decode($displayName);
        }

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

        $parts = [];
        $current = '';
        $inQuote = false;
        /** @var int $commentDepth */
        $commentDepth = 0;
        for ($i = 0, $length = strlen($mailboxListStr); $i < $length; $i++) {
            $char = $mailboxListStr[$i];
            if ($char === '\\' && ($inQuote || $commentDepth > 0) && ($i + 1) < $length) {
                $current .= $char . $mailboxListStr[++$i];
                continue;
            }

            if ($char === '(' && !$inQuote) {
                $commentDepth++;
            } elseif ($char === ')' && $commentDepth > 0) {
                $commentDepth--;
            } elseif ($char === '"' && $commentDepth === 0) {
                $inQuote = !$inQuote;
            } elseif ($char === ',' && !$inQuote && $commentDepth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

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
        if ($encoded === $this->displayName && preg_match('/[^a-zA-Z0-9 ]/', $this->displayName)) {
            $encoded = '"' . strtr($this->displayName, ['\\' => '\\\\', '"' => '\\"']) . '"';
        }

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
