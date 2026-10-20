# Message

The `Message` component implements RFC 5322 Internet Message construction, parsing, and serialization. It provides typed access to standard header fields (From, To, Subject, Date, Message-ID, etc.) with the message body represented as a MIME `PartInterface`.

## Construction

Messages are built using the fluent `with*()` methods. Each returns a new immutable instance. Address methods (`withFrom`, `withTo`, `withCc`, `withBcc`, `withReplyTo`) accept a plain string, a `Mailbox`, or an `AddressList`.

@example('protocols/message-construct.php')

## Multipart Bodies

The message body is a `PartInterface` from the MIME component. Compose any MIME structure using `Part\Text`, `Part\Data`, and the multipart types.

@example('protocols/message-multipart.php')

## Serialization and Parsing

`serialize()` produces a streaming RFC 5322 representation for transmission. It omits `Bcc` and `Resent-Bcc` headers so blind recipients stay private. The message keeps those headers, and `Envelope::fromMessage()` still includes its Bcc recipients. Derive the envelope before serializing; a parsed wire copy cannot recover blind recipients. `parse()` accepts a string or a `ReadHandleInterface` for streaming input from network sockets.

@example('protocols/message-serialize-parse.php')

## Reply, Reply All, Forward

Factory methods derive threading headers (In-Reply-To, References) and address fields from an original message per RFC 5322.

@example('protocols/message-reply.php')

## SMTP Envelope

`Envelope` derives the transport-level sender (MAIL FROM) and recipients (RCPT TO) from message headers per RFC 5321. These may differ from the visible From/To/Cc headers.

@example('protocols/message-envelope.php')

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

See `src/Psl/Message/` for the full API.
