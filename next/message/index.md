# Message

The `Message` component implements RFC 5322 Internet Message construction, parsing, and serialization. It provides typed access to standard header fields (From, To, Subject, Date, Message-ID, etc.) with the message body represented as a MIME `PartInterface`.

## Construction

Messages are built using the fluent `with*()` methods. Each returns a new immutable instance. Address methods (`withFrom`, `withTo`, `withCc`, `withBcc`, `withReplyTo`) accept a plain string, a `Mailbox`, or an `AddressList`.

```php
use Psl\IO;
use Psl\Message;
use Psl\MIME\Part;

// Header values must not contain CR, LF, or NUL.
// Mailbox quotes and escapes display names for use in headers.
// Addresses accept strings, Mailbox, or AddressList
$message = new Message\Message()
    ->withFrom('Alice <alice@example.com>')
    ->withTo('bob@example.com, carol@example.com')
    ->withSubject('Hello from PSL')
    ->withDate(Psl\DateTime\DateTime::now())
    ->withGeneratedMessageId()
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello, World!')));

$message->from; // AddressList
$message->to; // AddressList
$message->subject; // "Hello from PSL"
$message->messageId; // MessageId
$message->content; // PartInterface (the text part)

// Groups quote punctuation in their names and preserve member display names.
$team = new Message\Address\Group('Team: Europe', [
    new Message\Address\Mailbox('alice', 'example.com', 'Doe, Alice'),
]);
$team->toString(); // '"Team: Europe": "Doe, Alice" <alice@example.com>;'

// An ASCII local part lets the header use an A-label domain without SMTPUTF8.
new Message\Address\Mailbox('sender', 'münchen.de')->toString(); // sender@xn--mnchen-3ya.de
```

## Multipart Bodies

The message body is a `PartInterface` from the MIME component. Compose any MIME structure using `Part\Text`, `Part\Data`, and the multipart types.

```php
use Psl\IO;
use Psl\Message;
use Psl\MIME\MediaType;
use Psl\MIME\MultiPart;
use Psl\MIME\Part;

// Text + HTML alternatives
$alt = new MultiPart\Alternative();
$alt->addPart(new Part\Text(new IO\MemoryHandle('Plain text version')));
$alt->addPart(new Part\Text(new IO\MemoryHandle('<h1>HTML version</h1>'), 'html'));

// Wrap with an attachment
$mixed = new MultiPart\Composite($alt);
$mixed->addPart(
    new Part\Data(
        new IO\MemoryHandle('pdf content'),
        filename: 'report.pdf',
        mediaType: MediaType::parse('application/pdf'),
    ),
);

$message = new Message\Message()
    ->withFrom('alice@example.com')
    ->withTo('bob@example.com')
    ->withSubject('Report attached')
    ->withContent($mixed);
```

## Serialization and Parsing

`serialize()` produces a streaming RFC 5322 representation for transmission. It omits `Bcc` and `Resent-Bcc` headers so blind recipients stay private. The message keeps those headers, and `Envelope::fromMessage()` still includes its Bcc recipients. Derive the envelope before serializing; a parsed wire copy cannot recover blind recipients. `parse()` accepts a string or a `ReadHandleInterface` for streaming input from network sockets.

The parsers accept obsolete whitespace before header colons. They skip fields with invalid names or CR, LF, or NUL in unfolded values, and keep the other fields and the body. `parse()` throws `ParsingException` when no usable headers remain. Parsed address headers skip malformed entries, so one bad address does not hide valid recipients. `AddressList::parse()` and `Group::parse()` are strict by default; pass `lenient: true` to skip malformed entries.

```php
use Psl\IO;
use Psl\Message;
use Psl\MIME\Part;

$message = new Message\Message()
    ->withFrom('alice@example.com')
    ->withTo('bob@example.com')
    ->withBcc('secret@example.com')
    ->withSubject('Round trip')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello!')));

// Keep blind recipients in the envelope; omit them from the wire headers.
$envelope = Message\Envelope::fromMessage($message);

// Serialize to a streaming handle (headers + body)
$handle = Message\serialize($message);

// Parse back from a string or handle
$parsed = Message\parse($handle);

$parsed->subject; // "Round trip"
$parsed->from?->mailboxes()[0]?->address; // "alice@example.com"
$parsed->content->body()->readAll(); // encoded body content

// Received headers keep usable fields and recipients.
$received = Message\parse("Subject : hello\r\nTo: alice@example.com, invalid\r\n\r\nbody");
$received->subject; // "hello"
$received->to?->toString(); // "alice@example.com"
```

## Reply, Reply All, Forward

Factory methods derive threading headers (In-Reply-To, References) and address fields from an original message per RFC 5322.

```php
use Psl\IO;
use Psl\Message;
use Psl\Message\Address\Mailbox;
use Psl\MIME\Part;

$original = Message\parse(
    "From: alice@example.com\r\nTo: bob@example.com\r\nSubject: Hello\r\nMessage-ID: <msg-001@example.com>\r\n\r\nHi Bob!",
);

$me = Mailbox::parse('bob@example.com');

// Reply sets To from original From, prefixes subject, sets threading headers
$reply = Message\Message::reply($original, $me)->withContent(new Part\Text(new IO\MemoryHandle('Thanks Alice!')));

$reply->subject; // "Re: Hello"
$reply->inReplyTo; // [MessageId("msg-001@example.com")]
$reply->references; // [MessageId("msg-001@example.com")]

// Also available: Message::replyAll() and Message::forward()
```

## SMTP Envelope

`Envelope` derives the transport-level sender (MAIL FROM) and recipients (RCPT TO) from message headers per RFC 5321. These may differ from the visible From/To/Cc headers.

```php
use Psl\IO;
use Psl\Message;
use Psl\Message\Envelope;
use Psl\MIME\Part;

$message = new Message\Message()
    ->withFrom('alice@example.com')
    ->withTo('bob@example.com')
    ->withCc('carol@example.com')
    ->withBcc('dave@example.com')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello')));

// Derive SMTP envelope from message headers
$envelope = Envelope::fromMessage($message);

$envelope->sender; // Mailbox("alice@example.com")
$envelope->recipients; // [bob, carol, dave] (To + Cc + Bcc flattened)
```

## Addresses

The `Address` namespace provides RFC 5322 compliant address parsing:

- `Mailbox` represents a single email address with optional display name and RFC 5322 comments
- `Group` represents a named group of mailboxes (`display-name : mailbox-list ;`)
- `AddressList` holds an ordered list of mailboxes and groups, with merge and exclude operations

All address types support RFC 2047 encoded-word display names for non-ASCII characters. `Mailbox` accepts dot-atom or quoted local parts and dot-atom or domain-literal domains, including UTF-8 addresses. It escapes quotes and backslashes in display names, and parentheses and backslashes in comments. `Group` also quotes ASCII display names with punctuation and preserves quoted colons and commas when parsing.

When the local part is ASCII, address headers use lowercase, NFC-normalized A-label domains (`sender@münchen.de` becomes `sender@xn--mnchen-3ya.de`). Each encoded label must fit in 63 bytes. This uses the same normalization as SMTP, without full UTS #46 mapping. Domain literals and addresses with UTF-8 local parts stay unchanged; UTF-8 local parts still need SMTPUTF8. Raw values passed to `withHeader()` also stay unchanged.

Direct `Mailbox` construction rejects leading, trailing, or repeated dots in unquoted local parts, obsolete mixed quoted words, and a final domain dot. `Mailbox::parse()` accepts these forms: it quotes legacy local parts, joins obsolete quoted words, and removes the final domain dot. For example, `user.@ezweb.ne.jp` becomes `"user."@ezweb.ne.jp`, and `"john".doe@example.com` becomes `"john.doe"@example.com`.

Header construction rejects CR, LF, and NUL in names and values, mailbox fields, subjects, comments, message IDs, content IDs, and MIME parameters. `MIME\Headers` also rejects empty names, spaces, colons, and non-ASCII bytes in names. Pass unfolded values; the serializer handles line folding.

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 5322 | Message format, header fields, address syntax, threading |
| RFC 5321 | SMTP envelope derivation |
| RFC 2045 | MIME body integration via PartInterface |
| RFC 2047 | Encoded-word subjects and display names |

See [src/Psl/Message/](https://github.com/php-standard-library/php-standard-library/tree/next/packages/message/src/Psl/Message/) for the full API.
