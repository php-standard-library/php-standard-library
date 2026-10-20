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

The parsers accept obsolete whitespace before header colons. They skip fields with invalid names or CR, LF, or NUL in unfolded values, and keep the other fields and the body. `parse()` throws `ParsingException` when no usable headers remain. Parsed address headers skip malformed entries, so one bad address does not hide valid recipients. `AddressList::parse()` and `Group::parse()` are strict by default; pass `lenient: true` to skip malformed entries.

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

All address types support RFC 2047 encoded-word display names for non-ASCII characters. `Mailbox` accepts dot-atom or quoted local parts and dot-atom or domain-literal domains, including UTF-8 addresses. It escapes quotes and backslashes in display names, and parentheses and backslashes in comments.

Direct `Mailbox` construction rejects leading, trailing, or repeated dots in unquoted local parts, obsolete mixed quoted words, and a final domain dot. `Mailbox::parse()` accepts these forms: it quotes legacy local parts, joins obsolete quoted words, and removes the final domain dot. For example, `user.@ezweb.ne.jp` becomes `"user."@ezweb.ne.jp`, and `"john".doe@example.com` becomes `"john.doe"@example.com`.

Header construction rejects CR, LF, and NUL in names and values, mailbox fields, subjects, comments, message IDs, content IDs, and MIME parameters. `MIME\Headers` also rejects empty names, spaces, colons, and non-ASCII bytes in names. Pass unfolded values; the serializer handles line folding.

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 5322 | Message format, header fields, address syntax, threading |
| RFC 5321 | SMTP envelope derivation |
| RFC 2045 | MIME body integration via PartInterface |
| RFC 2047 | Encoded-word subjects and display names |

See `src/Psl/Message/` for the full API.
