<?php

declare(strict_types=1);

namespace Psl\Message\Address;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Psl\Message\Exception\ParsingException;
use Stringable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strtolower;
use function trim;

/**
 * Represents an ordered list of addresses ({@see Mailbox} or {@see Group}) per RFC 5322 section 3.4.
 *
 * Used for header fields that accept multiple addresses, such as "From", "To",
 * "Cc", "Bcc", and "Reply-To". Implements {@see Countable} and {@see IteratorAggregate}
 * for convenient iteration over the contained addresses.
 *
 * @implements IteratorAggregate<int, Mailbox|Group>
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.4
 *
 * @api
 */
final readonly class AddressList implements Countable, IteratorAggregate, Stringable
{
    /**
     * The addresses contained in this list.
     *
     * @var list<Mailbox|Group>
     */
    public array $addresses;

    /**
     * @param list<Mailbox|Group> $addresses
     */
    public function __construct(array $addresses)
    {
        $this->addresses = $addresses;
    }

    /**
     * Create an AddressList from one or more addresses.
     */
    public static function of(Mailbox|Group ...$addresses): self
    {
        return new self(array_values($addresses));
    }

    /**
     * Parse an address list from a header value.
     *
     * Handles comma-separated mailboxes and groups.
     * Groups are detected by `display-name : ... ;` syntax.
     * With $lenient, skip malformed entries in received headers.
     *
     * @throws ParsingException If the input cannot be parsed.
     */
    public static function parse(string $input, bool $lenient = false): self
    {
        $input = trim($input, " \t\r\n");

        if ($input === '') {
            return new self([]);
        }

        $addresses = [];
        $segments = self::splitAddresses($input);

        foreach ($segments as $segment) {
            $segment = trim($segment, " \t\r\n");
            if ($segment === '') {
                continue;
            }

            try {
                if (str_contains($segment, ':') && str_ends_with(trim($segment, " \t\r\n"), ';')) {
                    $addresses[] = Group::parse($segment, $lenient);
                } else {
                    $addresses[] = Mailbox::parse($segment);
                }
            } catch (ParsingException $exception) {
                if (!$lenient) {
                    throw $exception;
                }
            }
        }

        return new self($addresses);
    }

    /**
     * Return a new list with the given address lists merged.
     */
    public static function merge(self ...$lists): self
    {
        $addresses = [];
        foreach ($lists as $list) {
            foreach ($list->addresses as $address) {
                $addresses[] = $address;
            }
        }

        return new self($addresses);
    }

    /**
     * Return a new list with the given mailbox excluded.
     *
     * Comparison is case-insensitive on the full address. Within groups,
     * matching mailboxes are removed; empty groups are dropped entirely.
     */
    public function exclude(Mailbox $mailbox): self
    {
        $excludeAddress = strtolower($mailbox->address);
        $addresses = [];

        foreach ($this->addresses as $address) {
            if ($address instanceof Mailbox) {
                if (strtolower($address->address) !== $excludeAddress) {
                    $addresses[] = $address;
                }
            } else {
                $filtered = array_values(array_filter(
                    $address->mailboxes,
                    static fn(Mailbox $m): bool => strtolower($m->address) !== $excludeAddress,
                ));

                if ($filtered !== []) {
                    $addresses[] = new Group($address->displayName, $filtered);
                }
            }
        }

        return new self($addresses);
    }

    /**
     * Get all mailboxes, flattening groups.
     *
     * @return list<Mailbox>
     */
    public function mailboxes(): array
    {
        $result = [];
        foreach ($this->addresses as $address) {
            if ($address instanceof Mailbox) {
                $result[] = $address;
            } else {
                foreach ($address->mailboxes as $mailbox) {
                    $result[] = $mailbox;
                }
            }
        }

        return $result;
    }

    /**
     * Return the number of top-level addresses (mailboxes and groups) in the list.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->addresses);
    }

    /**
     * @return ArrayIterator<int, Mailbox|Group>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->addresses);
    }

    /**
     * Serialize for use in headers.
     */
    public function toString(): string
    {
        $parts = array_map(static fn(Mailbox|Group $a): string => $a->toString(), $this->addresses);

        return implode(', ', $parts);
    }

    /**
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Split an address header value into individual address/group segments.
     *
     * Commas inside angle brackets or group colons are not treated as separators.
     *
     * @return list<string>
     */
    private static function splitAddresses(string $input): array
    {
        $segments = [];
        $current = '';
        /** @var int $depth */
        $depth = 0;
        $inGroup = false;
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];

            if ($char === '"') {
                $current .= $char;
                $i++;
                while ($i < $len && $input[$i] !== '"') {
                    if ($input[$i] === '\\' && ($i + 1) < $len) {
                        $current .= $input[$i] . $input[$i + 1];
                        $i += 2;

                        continue;
                    }

                    $current .= $input[$i];
                    $i++;
                }

                if ($i < $len) {
                    $current .= $input[$i];
                }

                continue;
            }

            if ($char === '<') {
                $depth++;
                $current .= $char;

                continue;
            }

            if ($char === '>' && $depth > 0) {
                $depth--;
                $current .= $char;

                continue;
            }

            if ($char === ':' && $depth === 0) {
                $inGroup = true;
                $current .= $char;

                continue;
            }

            if ($char === ';' && $inGroup) {
                $inGroup = false;
                $current .= $char;
                $segments[] = $current;
                $current = '';

                continue;
            }

            if ($char === ',' && $depth === 0 && !$inGroup) {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current, " \t\r\n") !== '') {
            $segments[] = $current;
        }

        return $segments;
    }
}
