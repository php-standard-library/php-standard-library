<?php

declare(strict_types=1);

namespace Psl\Message\Address;

use Psl\Encoding\EncodedWord;
use Psl\Message\Exception\InvalidMailboxException;
use Psl\Message\Exception\ParsingException;
use Stringable;

use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strpos;
use function strrpos;
use function strtr;
use function substr;
use function trim;

/**
 * Represents a single mailbox address per RFC 5322 section 3.4.
 *
 * A mailbox is the combination of a local-part, domain, and optional display
 * name. It models the `[display-name] angle-addr` or plain `addr-spec` syntax.
 * RFC 5322 section 3.2.2 comments are preserved when parsed.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.4
 * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.2.2
 * @link https://datatracker.ietf.org/doc/html/rfc2047
 *
 * @api
 */
final readonly class Mailbox implements Stringable
{
    private const string ATOM = '[a-zA-Z0-9!#$%&\'*+/=?^_`{|}\x7e\x{80}-\x{10ffff}-]+';

    private const string QUOTED = '"(?:[\x09\x20\x21\x23-\x5b\x5d-\x7e\x{80}-\x{10ffff}]|\\\\[\x09\x20-\x7e])*"';

    /**
     * The local part of the address (before @).
     */
    public string $localPart;

    /**
     * The domain part of the address (after @).
     */
    public string $domain;

    /**
     * Optional display name.
     */
    public null|string $displayName;

    /**
     * The full address: localPart@domain.
     */
    public string $address;

    /**
     * Optional RFC 5322 comment text (without parentheses).
     */
    public null|string $comment;

    /**
     * @throws InvalidMailboxException If the local part or domain is invalid.
     */
    public function __construct(
        string $localPart,
        string $domain,
        null|string $displayName = null,
        null|string $comment = null,
    ) {
        if ($localPart === '') {
            throw InvalidMailboxException::forEmptyLocalPart();
        }

        if ($domain === '') {
            throw InvalidMailboxException::forEmptyDomain();
        }

        // RFC 5322 dot-atoms, with UTF-8 characters allowed by RFC 6532.
        $dotAtom = self::ATOM . '(?:\.' . self::ATOM . ')*';
        if (preg_match('~\A(?:' . $dotAtom . '|' . self::QUOTED . ')\z~u', $localPart) !== 1) {
            throw InvalidMailboxException::forInvalidLocalPart($localPart);
        }

        if (preg_match('~\A(?:' . $dotAtom . '|\[[\x21-\x5a\x5e-\x7e]+\])\z~u', $domain) !== 1) {
            throw InvalidMailboxException::forInvalidDomain($domain);
        }

        if ($displayName !== null && strpbrk($displayName, "\r\n\0") !== false) {
            throw InvalidMailboxException::forInvalidDisplayName();
        }

        if ($comment !== null && strpbrk($comment, "\r\n\0") !== false) {
            throw InvalidMailboxException::forInvalidComment();
        }

        $this->localPart = $localPart;
        $this->domain = $domain;
        $this->displayName = $displayName;
        $this->address = $localPart . '@' . $domain;
        $this->comment = $comment;
    }

    /**
     * Parse a mailbox from a string.
     *
     * Accepts:
     * - `"Display Name" <local@domain>`
     * - `Display Name <local@domain>`
     * - `<local@domain>`
     * - `local@domain`
     * - `local@domain (comment)` -RFC 5322 §3.2.2 comments
     * - `john@(comment)example.com` -comments within addr-spec
     * - Encoded-word display names per RFC 2047
     *
     * Quotes legacy dotted local parts and obsolete quoted-word sequences.
     * Removes a final domain dot. Direct construction requires current syntax.
     *
     * @throws ParsingException If the input is not a valid mailbox.
     */
    public static function parse(string $input): self
    {
        $input = trim($input, " \t\r\n");

        if ($input === '') {
            throw ParsingException::forInvalidMailbox($input);
        }

        [$input, $comment] = self::stripComments($input);
        $input = trim($input, " \t\r\n");

        if ($input === '') {
            throw ParsingException::forInvalidMailbox($input);
        }

        $angleBracketPos = strpos($input, '<');
        if ($angleBracketPos !== false) {
            $closeBracketPos = strpos($input, '>', $angleBracketPos);
            if ($closeBracketPos === false) {
                throw ParsingException::forInvalidMailbox($input);
            }

            /** @var non-negative-int $addrSpecLen */
            $addrSpecLen = $closeBracketPos - $angleBracketPos - 1;
            $addrSpec = substr($input, $angleBracketPos + 1, $addrSpecLen);
            $displayPart = trim(substr($input, 0, $angleBracketPos), " \t\r\n");

            $displayName = self::parseDisplayName($displayPart);

            return self::fromAddrSpec($addrSpec, $displayName, $comment, $input);
        }

        return self::fromAddrSpec($input, null, $comment, $input);
    }

    /**
     * Serialize the mailbox for use in headers.
     *
     * Display names containing non-ASCII characters are encoded per RFC 2047.
     * Comments are serialized after the address per RFC 5322 §3.2.2.
     */
    public function toString(): string
    {
        $result = '';

        if ($this->displayName === null || $this->displayName === '') {
            $result = $this->address;
        } else {
            $encoded = EncodedWord\encode($this->displayName);

            if ($encoded === $this->displayName && preg_match('/[^a-zA-Z0-9 ]/', $this->displayName)) {
                $result = '"' . strtr($this->displayName, [
                    '\\' => '\\\\',
                    '"' => '\\"',
                ]) . '" <' . $this->address . '>';
            } else {
                $result = $encoded . ' <' . $this->address . '>';
            }
        }

        if ($this->comment !== null) {
            $result .= ' (' . strtr($this->comment, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }

        return $result;
    }

    /**
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Strip RFC 5322 §3.2.2 comments from input.
     *
     * Comments are parenthesized text that can be nested. Backslash escapes
     * within comments are respected (e.g. `\)` does not close the comment).
     * Quoted strings are not scanned for comments.
     *
     * @return array{string, null|string} The stripped input and the first comment text (without parens), or null.
     */
    private static function stripComments(string $input): array
    {
        $result = '';
        $commentText = null;
        $currentComment = '';
        /** @var int $depth */
        $depth = 0;
        $len = strlen($input);
        $inQuote = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];

            if ($char === '\\' && ($i + 1) < $len) {
                if ($depth > 0) {
                    $currentComment .= $input[$i + 1];
                } else {
                    $result .= $char . $input[$i + 1];
                }

                $i++;

                continue;
            }

            if ($char === '"' && $depth === 0) {
                $inQuote = !$inQuote;
                $result .= $char;

                continue;
            }

            if ($inQuote) {
                $result .= $char;

                continue;
            }

            if ($char === '(') {
                if ($depth > 0) {
                    $currentComment .= $char;
                }

                $depth += 1;

                continue;
            }

            if ($char === ')' && $depth > 0) {
                $depth -= 1;
                if ($depth === 0) {
                    $commentText ??= $currentComment;
                    $currentComment = '';
                } else {
                    $currentComment .= $char;
                }

                continue;
            }

            if ($depth > 0) {
                $currentComment .= $char;
            } else {
                $result .= $char;
            }
        }

        return [$result, $commentText];
    }

    /**
     * Parse the display-name portion before an angle-bracket address.
     *
     * Handles quoted strings and RFC 2047 encoded-words.
     */
    private static function parseDisplayName(string $displayPart): null|string
    {
        if ($displayPart === '') {
            return null;
        }

        if (str_starts_with($displayPart, '"') && str_ends_with($displayPart, '"') && strlen($displayPart) >= 2) {
            /** @var non-negative-int $innerLen */
            $innerLen = strlen($displayPart) - 2;

            return preg_replace('/\\\\([\x09\x20-\x7e])/', '$1', substr($displayPart, 1, $innerLen));
        }

        return EncodedWord\decode($displayPart);
    }

    /**
     * @throws ParsingException
     */
    private static function fromAddrSpec(
        string $addrSpec,
        null|string $displayName,
        null|string $comment,
        string $originalInput,
    ): self {
        $addrSpec = trim($addrSpec, " \t\r\n");
        $atPos = strrpos($addrSpec, '@');

        if ($atPos === false || $atPos === 0 || $atPos === (strlen($addrSpec) - 1)) {
            throw ParsingException::forInvalidMailbox($originalInput);
        }

        $localPart = substr($addrSpec, 0, $atPos);
        $domain = substr($addrSpec, $atPos + 1);
        if (str_ends_with($domain, '.')) {
            $domain = substr($domain, 0, -1);
        }

        $dotAtom = self::ATOM . '(?:\.' . self::ATOM . ')*';
        if (preg_match('~\A(?:' . $dotAtom . '|' . self::QUOTED . ')\z~u', $localPart) !== 1) {
            $word = '(?:' . self::ATOM . '|' . self::QUOTED . ')?';
            if (preg_match('~\A' . $word . '(?:\.' . $word . ')*\z~u', $localPart) !== 1) {
                throw ParsingException::forInvalidMailbox($originalInput);
            }

            $localPart =
                preg_replace_callback(
                    '~' . self::QUOTED . '~u',
                    static fn(array $match): string => (
                        preg_replace('/\\\\([\x09\x20-\x7e])/', '$1', substr($match[0], 1, -1)) ?? ''
                    ),
                    $localPart,
                ) ?? $localPart;
            $localPart = '"' . strtr($localPart, ['\\' => '\\\\', '"' => '\\"']) . '"';
        }

        try {
            return new self($localPart, $domain, $displayName, $comment);
        } catch (InvalidMailboxException) {
            throw ParsingException::forInvalidMailbox($originalInput);
        }
    }
}
