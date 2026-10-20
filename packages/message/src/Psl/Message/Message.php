<?php

declare(strict_types=1);

namespace Psl\Message;

use Psl\DateTime;
use Psl\DateTime\DateTimeInterface;
use Psl\DateTime\FormatPattern;
use Psl\Encoding\EncodedWord;
use Psl\Encoding\Exception\ParsingException;
use Psl\IO;
use Psl\Message\Address\AddressList;
use Psl\Message\Address\Mailbox;
use Psl\MIME\Exception\EntropyException;
use Psl\MIME\Headers;
use Psl\MIME\Part\Part;
use Psl\MIME\Part\PartInterface;

use function array_map;
use function implode;
use function preg_match_all;
use function str_starts_with;
use function strtolower;
use function trim;

use const PREG_SET_ORDER;

/**
 * Immutable value object representing an RFC 5322 Internet Message.
 *
 * Typed properties correspond to the standard header fields defined in RFC 5322.
 * The body is a MIME part per RFC 2045. All mutation methods (`with*()`) return
 * a new instance, leaving the original unchanged.
 *
 * Construct a blank message via `new Message()` and populate with `with*()` methods,
 * or parse a raw message using the {@see parse()} function.
 *
 * Convenience factory methods {@see reply()}, {@see replyAll()}, and {@see forward()}
 * create correctly threaded responses to an existing message.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322
 * @link https://datatracker.ietf.org/doc/html/rfc2045
 *
 * @api
 *
 * @mago-expect lint:too-many-properties
 */
final class Message implements MessageInterface
{
    /**
     * The raw MIME headers.
     */
    public private(set) Headers $headers;

    /**
     * Originator addresses (RFC 5322 SS3.6.2).
     */
    public private(set) null|AddressList $from;

    /**
     * Single sender mailbox (RFC 5322 SS3.6.2).
     */
    public private(set) null|Mailbox $sender;

    /**
     * Primary recipient addresses (RFC 5322 SS3.6.3).
     */
    public private(set) null|AddressList $to;

    /**
     * Carbon-copy recipient addresses (RFC 5322 SS3.6.3).
     */
    public private(set) null|AddressList $cc;

    /**
     * Blind carbon-copy recipient addresses (RFC 5322 SS3.6.3).
     */
    public private(set) null|AddressList $bcc;

    /**
     * Reply-To addresses (RFC 5322 SS3.6.2).
     */
    public private(set) null|AddressList $replyTo;

    /**
     * Origination date (RFC 5322 SS3.6.1).
     */
    public private(set) null|DateTimeInterface $date;

    /**
     * Unique message identifier (RFC 5322 SS3.6.4).
     */
    public private(set) null|MessageId $messageId;

    /**
     * Decoded subject line (RFC 5322 SS3.6.5).
     */
    public private(set) null|string $subject;

    /**
     * Message IDs from the References header (RFC 5322 SS3.6.4).
     *
     * @var list<MessageId>
     */
    public private(set) array $references;

    /**
     * Message IDs from the In-Reply-To header (RFC 5322 SS3.6.4).
     *
     * @var list<MessageId>
     */
    public private(set) array $inReplyTo;

    /**
     * The message body as a MIME part (RFC 2045).
     */
    public private(set) PartInterface $content;

    /**
     * Construct a message, optionally from pre-parsed headers and body.
     *
     * When headers are provided (e.g. from parsing), structured fields are
     * extracted eagerly. Malformed values are silently treated as absent.
     */
    public function __construct(null|Headers $headers = null, null|PartInterface $body = null)
    {
        $this->headers = $headers ?? Headers::default();
        $this->content = $body ?? new Part(Headers::default(), new IO\MemoryHandle(''));

        if (0 === $this->headers->count()) {
            $this->from = null;
            $this->sender = null;
            $this->to = null;
            $this->cc = null;
            $this->bcc = null;
            $this->replyTo = null;
            $this->date = null;
            $this->messageId = null;
            $this->subject = null;
            $this->references = [];
            $this->inReplyTo = [];
        } else {
            $this->from = self::parseAddressList($this->headers->get('From'));
            $this->sender = self::parseSender($this->headers->get('Sender'));
            $this->to = self::parseAddressList($this->headers->get('To'));
            $this->cc = self::parseAddressList($this->headers->get('Cc'));
            $this->bcc = self::parseAddressList($this->headers->get('Bcc'));
            $this->replyTo = self::parseAddressList($this->headers->get('Reply-To'));
            $this->date = self::parseDate($this->headers->get('Date'));
            $this->messageId = self::parseMessageId($this->headers->get('Message-ID'));
            $this->subject = self::parseSubject($this->headers->get('Subject'));
            $this->references = self::parseMessageIdList($this->headers->get('References'));
            $this->inReplyTo = self::parseMessageIdList($this->headers->get('In-Reply-To'));
        }
    }

    /**
     * Create a reply to the given message.
     *
     * Sets the "To" field to the original's "Reply-To" (or "From" as fallback),
     * prefixes the subject with "Re:", and sets up threading headers
     * ("In-Reply-To" and "References") per RFC 5322 section 3.6.4.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.4
     */
    public static function reply(MessageInterface $original, Mailbox $from): self
    {
        $message = new self()
            ->withFrom(AddressList::of($from))
            ->withTo($original->replyTo ?? $original->from ?? AddressList::of($from));

        $subject = self::prefixSubject($original->subject, 'Re');
        if ($subject !== null) {
            $message = $message->withSubject($subject);
        }

        return self::applyThreading($message, $original);
    }

    /**
     * Create a reply-all to the given message.
     *
     * Like {@see reply()}, but additionally copies the original "To" and "Cc"
     * recipients (excluding the sender) into the "Cc" field, so all original
     * participants receive the reply.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc5322#section-3.6.4
     */
    public static function replyAll(MessageInterface $original, Mailbox $from): self
    {
        $to = $original->replyTo ?? $original->from ?? AddressList::of($from);

        $ccLists = [];
        if ($original->to !== null) {
            $ccLists[] = $original->to;
        }

        if ($original->cc !== null) {
            $ccLists[] = $original->cc;
        }

        $cc = AddressList::merge(...$ccLists)->exclude($from);

        $message = new self()
            ->withFrom(AddressList::of($from))
            ->withTo($to);

        $subject = self::prefixSubject($original->subject, 'Re');
        if ($subject !== null) {
            $message = $message->withSubject($subject);
        }

        if ($cc->count() > 0) {
            $message = $message->withCc($cc);
        }

        return self::applyThreading($message, $original);
    }

    /**
     * Create a forward of the given message.
     *
     * Prefixes the subject with "Fwd:" and sets up threading headers.
     * The "To" field is left empty for the caller to populate.
     */
    public static function forward(MessageInterface $original, Mailbox $from): self
    {
        $message = new self()->withFrom(AddressList::of($from));

        $subject = self::prefixSubject($original->subject, 'Fwd');
        if ($subject !== null) {
            $message = $message->withSubject($subject);
        }

        return self::applyThreading($message, $original);
    }

    /**
     * Return a new message with the given "From" addresses.
     *
     * @throws Exception\ParsingException If a string value cannot be parsed.
     */
    public function withFrom(string|Mailbox|AddressList $from): self
    {
        $from = self::toAddressList($from);

        $message = clone $this;
        $message->from = $from;
        $message->headers = $this->headers->replace('From', $from->toString());

        return $message;
    }

    /**
     * Return a new message with the given "Sender" mailbox.
     *
     * @throws Exception\ParsingException If a string value cannot be parsed.
     */
    public function withSender(string|Mailbox $sender): self
    {
        $sender = self::toMailbox($sender);

        $message = clone $this;
        $message->sender = $sender;
        $message->headers = $this->headers->replace('Sender', $sender->toString());

        return $message;
    }

    /**
     * Return a new message with the given "To" addresses.
     *
     * @throws Exception\ParsingException If a string value cannot be parsed.
     */
    public function withTo(string|Mailbox|AddressList $to): self
    {
        $to = self::toAddressList($to);

        $message = clone $this;
        $message->to = $to;
        $message->headers = $this->headers->replace('To', $to->toString());

        return $message;
    }

    /**
     * Return a new message with the given "Cc" addresses.
     *
     * @throws Exception\ParsingException If a string value cannot be parsed.
     */
    public function withCc(string|Mailbox|AddressList $cc): self
    {
        $cc = self::toAddressList($cc);

        $message = clone $this;
        $message->cc = $cc;
        $message->headers = $this->headers->replace('Cc', $cc->toString());

        return $message;
    }

    /**
     * Return a new message with the given "Bcc" addresses.
     *
     * @throws Exception\ParsingException If a string value cannot be parsed.
     */
    public function withBcc(string|Mailbox|AddressList $bcc): self
    {
        $bcc = self::toAddressList($bcc);

        $message = clone $this;
        $message->bcc = $bcc;
        $message->headers = $this->headers->replace('Bcc', $bcc->toString());

        return $message;
    }

    /**
     * Return a new message with the given "Reply-To" addresses.
     *
     * @throws Exception\ParsingException If a string value cannot be parsed.
     */
    public function withReplyTo(string|Mailbox|AddressList $replyTo): self
    {
        $replyTo = self::toAddressList($replyTo);

        $message = clone $this;
        $message->replyTo = $replyTo;
        $message->headers = $this->headers->replace('Reply-To', $replyTo->toString());

        return $message;
    }

    /**
     * Return a new message with the given "Date" value.
     *
     * The date is serialized to RFC 2822 format in the headers.
     */
    public function withDate(DateTimeInterface $date): self
    {
        $message = clone $this;
        $message->date = $date;
        $message->headers = $this->headers->replace('Date', $date->format(FormatPattern::Rfc2822));

        return $message;
    }

    /**
     * Return a new message with the given "Message-ID".
     */
    public function withMessageId(MessageId $messageId): self
    {
        $message = clone $this;
        $message->messageId = $messageId;
        $message->headers = $this->headers->replace('Message-ID', $messageId->toString());

        return $message;
    }

    /**
     * Return a new message with a randomly generated "Message-ID".
     *
     * @param string $domain The domain portion of the generated ID (after the "@").
     *
     * @throws EntropyException If the system cannot generate secure random bytes.
     *
     * @see MessageId::generate()
     */
    public function withGeneratedMessageId(string $domain = 'php-standard-library.dev'): self
    {
        return $this->withMessageId(MessageId::generate($domain));
    }

    /**
     * Return a new message with the given subject line.
     *
     * The subject is encoded using RFC 2047 encoded-words in the headers.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc2047
     */
    public function withSubject(string $subject): self
    {
        $message = clone $this;
        $message->subject = $subject;
        $message->headers = $this->headers->replace('Subject', EncodedWord\encode($subject));

        return $message;
    }

    /**
     * Return a new message with the given custom header appended.
     *
     * Unlike the typed `with*()` methods, this does not update any structured
     * property; the header is added as-is to the raw {@see Headers} collection.
     */
    public function withHeader(string $name, string $value): self
    {
        $message = clone $this;
        $message->headers = $this->headers->with($name, $value);

        return $message;
    }

    /**
     * Return a new message with the given "References" header value.
     *
     * An empty array removes the "References" header entirely.
     *
     * @param list<MessageId> $references
     */
    public function withReferences(array $references): self
    {
        $message = clone $this;
        $message->references = $references;
        $message->headers = $references === []
            ? $this->headers->without('References')
            : $this->headers->replace('References', implode(' ', array_map(
                static fn(MessageId $id): string => $id->toString(),
                $references,
            )));

        return $message;
    }

    /**
     * Return a new message with the given "In-Reply-To" header value.
     *
     * An empty array removes the "In-Reply-To" header entirely.
     *
     * @param list<MessageId> $inReplyTo
     */
    public function withInReplyTo(array $inReplyTo): self
    {
        $message = clone $this;
        $message->inReplyTo = $inReplyTo;
        $message->headers = $inReplyTo === []
            ? $this->headers->without('In-Reply-To')
            : $this->headers->replace('In-Reply-To', implode(' ', array_map(
                static fn(MessageId $id): string => $id->toString(),
                $inReplyTo,
            )));

        return $message;
    }

    /**
     * Return a new message with the given MIME part as the body.
     */
    public function withContent(PartInterface $content): self
    {
        $message = clone $this;
        $message->content = $content;

        return $message;
    }

    private static function toAddressList(string|Mailbox|AddressList $value): AddressList
    {
        return match (true) {
            $value instanceof AddressList => $value,
            $value instanceof Mailbox => AddressList::of($value),
            default => AddressList::parse($value),
        };
    }

    private static function toMailbox(string|Mailbox $value): Mailbox
    {
        return $value instanceof Mailbox ? $value : Mailbox::parse($value);
    }

    private static function applyThreading(self $message, MessageInterface $original): self
    {
        if ($original->messageId !== null) {
            $message = $message->withReferences([...$original->references, $original->messageId])->withInReplyTo([
                $original->messageId,
            ]);
        }

        return $message;
    }

    private static function prefixSubject(null|string $subject, string $prefix): null|string
    {
        if ($subject === null) {
            return null;
        }

        if (str_starts_with(strtolower($subject), strtolower($prefix . ':'))) {
            return $subject;
        }

        return $prefix . ': ' . $subject;
    }

    private static function parseAddressList(null|string $value): null|AddressList
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $list = AddressList::parse($value);

            return $list->addresses === [] ? null : $list;
        } catch (Exception\ParsingException) {
            return null;
        }
    }

    private static function parseSender(null|string $value): null|Mailbox
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Mailbox::parse($value);
        } catch (Exception\ParsingException) {
            return null;
        }
    }

    private static function parseDate(null|string $value): null|DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return DateTime\DateTime::parse(trim($value, " \t\r\n"), FormatPattern::Rfc2822);
        } catch (DateTime\Exception\RuntimeException) {
            return null;
        }
    }

    private static function parseMessageId(null|string $value): null|MessageId
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return MessageId::parse($value);
        } catch (Exception\ParsingException) {
            return null;
        }
    }

    private static function parseSubject(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        try {
            return EncodedWord\decode($value);
        } catch (ParsingException) {
            return $value;
        }
    }

    /**
     * @return list<MessageId>
     */
    private static function parseMessageIdList(null|string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $ids = [];
        $matches = [];
        preg_match_all('/<[^>]+>/', $value, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return [];
        }

        foreach ($matches as $match) {
            try {
                $ids[] = MessageId::parse($match[0]);
            } catch (Exception\ParsingException) {
                // @mago-expect lint:no-empty-catch-clause - skip malformed IDs
            }
        }

        return $ids;
    }
}
