# Message

The `Message` component implements RFC 5322 Internet Message construction, parsing, and serialization. It provides typed access to standard header fields (From, To, Subject, Date, Message-ID, etc.) with the message body represented as a MIME `PartInterface`.

## Construction

Messages are built using the fluent `with*()` methods. Each returns a new immutable instance. Address methods (`withFrom`, `withTo`, `withCc`, `withBcc`, `withReplyTo`) accept a plain string, a `Mailbox`, or an `AddressList`.

```php
use Psl\IO;
use Psl\Message;
use Psl\MIME\Part;

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

`serialize()` produces a streaming RFC 5322 representation. `parse()` accepts a string or a `ReadHandleInterface` for streaming input from network sockets.

```php
use Psl\IO;
use Psl\Message;
use Psl\MIME\Part;

$message = new Message\Message()
    ->withFrom('alice@example.com')
    ->withTo('bob@example.com')
    ->withSubject('Round trip')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello!')));

// Serialize to a streaming handle (headers + body)
$handle = Message\serialize($message);

// Parse back from a string or handle
$parsed = Message\parse($handle);

$parsed->subject; // "Round trip"
$parsed->from?->mailboxes()[0]?->address; // "alice@example.com"
$parsed->content->body()->readAll(); // encoded body content
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

All address types support RFC 2047 encoded-word display names for non-ASCII characters.

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 5322 | Message format, header fields, address syntax, threading |
| RFC 5321 | SMTP envelope derivation |
| RFC 2045 | MIME body integration via PartInterface |
| RFC 2047 | Encoded-word subjects and display names |

See [src/Psl/Message/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/message/src/Psl/Message/) for the full API.
