# SMTP

The `SMTP` component implements an RFC 5321 SMTP client with connection pooling, TLS, authentication, and support for modern SMTP extensions. It integrates with the `Message` and `MIME` components for complete email sending.

## Sending a Message

The `Transport` handles the full SMTP lifecycle: connection, EHLO, TLS, authentication, message transmission, and connection reuse. Pass an `Envelope` (derived from message headers) and a `MessageInterface` to send.

For envelope addresses, the transport lowercases and NFC-normalizes domain labels, then encodes each Unicode label as Punycode with the `xn--` prefix. Domain literals stay unchanged. It rejects encoded labels longer than 63 bytes with `TransmissionException`. This handles case and canonical Unicode forms, but does not apply the full UTS #46 mapping. Non-ASCII envelope local parts require SMTPUTF8; the transport throws `TransmissionException` before MAIL FROM when the server lacks it (RFC 6531 section 3.2).

@example('protocols/smtp-basic.php')

## Authentication

Five authentication mechanisms are supported. Pass any `AuthenticatorInterface` as the second argument to `Transport`.

@example('protocols/smtp-auth.php')

## Transport Configuration

`TransportConfiguration` controls connection-level settings: host, port, security mode, pipelining, chunking, and TCP/TLS options. All settings are immutable with fluent `with*()` builders.

@example('protocols/smtp-config.php')

## Send Configuration

`SendConfiguration` controls per-send MAIL FROM extension parameters: DSN notifications, REQUIRETLS, message priority, delivery deadlines, and deferred delivery. When `requireTls` is true, the transport throws `TransmissionException` before sending MAIL FROM if the server does not advertise REQUIRETLS or the connection uses `Security::None` (RFC 8689 section 4.2.1). The transport keeps EHLO capabilities when it reuses a pooled connection.

@example('protocols/smtp-send-config.php')

## Partial Recipient Success

By default, the transport throws on the first rejected recipient. Enable `allowPartialSuccess` to deliver to accepted recipients and report failures via `DeliveryReport`.

@example('protocols/smtp-partial.php')

## Async and Cancellation

The transport is fully async-capable. Use `Async\concurrently` to send multiple messages in parallel with automatic connection pooling. Use cancellation tokens for timeouts.

@example('protocols/smtp-async.php')

## Low-Level Connection

`Connection` implements `Network\StreamInterface` and speaks raw SMTP commands. Use it for direct protocol interaction or to build custom transports.

@example('protocols/smtp-connection.php')

## SMTP Extensions

The transport automatically negotiates these extensions when the server advertises them:

| Extension | RFC | Description |
|-----------|-----|-------------|
| PIPELINING | RFC 2920 | Send multiple commands before reading replies |
| CHUNKING | RFC 3030 | BDAT transfer without dot-stuffing |
| BINARYMIME | RFC 3030 | Binary content without transfer encoding |
| 8BITMIME | RFC 6152 | 8-bit MIME transport |
| SMTPUTF8 | RFC 6531 | Internationalized email addresses |
| STARTTLS | RFC 3207 | TLS upgrade on plaintext connections |
| DSN | RFC 3461 | Delivery status notifications |
| REQUIRETLS | RFC 8689 | End-to-end TLS enforcement |
| MT-PRIORITY | RFC 6710 | Message priority levels |
| DELIVERBY | RFC 2852 | Delivery deadline specification |
| FUTURERELEASE | RFC 4865 | Deferred delivery |
| SIZE | RFC 1870 | Server maximum message size |

## Security

The transport validates all SMTP commands for CRLF and null byte injection attacks. Malicious input in addresses, hostnames, or DSN parameters throws `PossibleAttackException` before reaching the wire.

See `src/Psl/SMTP/` for the full API.
