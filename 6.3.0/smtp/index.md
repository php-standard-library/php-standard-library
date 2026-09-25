# SMTP

The `SMTP` component implements an RFC 5321 SMTP client with connection pooling, TLS, authentication, and support for modern SMTP extensions. It integrates with the `Message` and `MIME` components for complete email sending.

## Sending a Message

The `Transport` handles the full SMTP lifecycle: connection, EHLO, TLS, authentication, message transmission, and connection reuse. Pass an `Envelope` (derived from message headers) and a `MessageInterface` to send.

```php
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Part;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Security;

$transport = new Transport(
    TransportConfiguration::default()->withHost('smtp.example.com')->withSecurity(Security::TLS),
);

$sender = new Mailbox('alice', 'example.com', 'Alice');
$recipient = new Mailbox('bob', 'example.com');

$message = new Message()
    ->withFrom($sender)
    ->withTo($recipient)
    ->withSubject('Hello from PSL')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello, Bob!')));

$report = $transport->send(Envelope::fromMessage($message), $message);

$transport->close();
```

## Authentication

Five authentication mechanisms are supported. Pass any `AuthenticatorInterface` as the second argument to `Transport`.

```php
use Psl\SMTP\Client\Authentication;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Security;

// PLAIN authentication
$plain = new Authentication\PlainAuthenticator('user', 'password');

// LOGIN authentication
$login = new Authentication\LoginAuthenticator('user', 'password');

// XOAUTH2 for Gmail and other OAuth2-enabled servers
$oauth = new Authentication\XOAuth2Authenticator('user@gmail.com', 'oauth2-access-token');

// CRAM-MD5 challenge-response (avoids sending password in plaintext)
$cramMd5 = new Authentication\CRAMMD5Authenticator('user', 'password');

// SCRAM-SHA-256 with mutual authentication (RFC 7677)
$scram = new Authentication\SCRAMSHA256Authenticator('user', 'password');

// Pass any authenticator as the second argument to Transport
$transport = new Transport(
    TransportConfiguration::default()->withHost('smtp.gmail.com')->withSecurity(Security::TLS),
    $oauth,
);
```

## Transport Configuration

`TransportConfiguration` controls connection-level settings: host, port, security mode, pipelining, chunking, and TCP/TLS options. All settings are immutable with fluent `with*()` builders.

```php
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Security;
use Psl\TCP;
use Psl\TLS;

// All transport settings are immutable with fluent with*() builders
$config = TransportConfiguration::default()
    ->withHost('smtp.example.com')
    ->withPort(465)
    ->withSecurity(Security::TLS)
    ->withLocalHostname('client.example.com')
    ->withPipelining(true)
    ->withChunking(true)
    ->withChunkSize(32_768)
    ->withAllowPartialSuccess(false);

// TCP and TLS settings are configurable independently
$config = $config
    ->withConnectConfiguration(new TCP\ConnectConfiguration(noDelay: true))
    ->withTlsClientConfiguration(new TLS\ClientConfiguration(peerVerification: true, allowSelfSigned: false));
```

## Send Configuration

`SendConfiguration` controls per-send MAIL FROM extension parameters: DSN notifications, REQUIRETLS, message priority, delivery deadlines, and deferred delivery.

```php
use Psl\DateTime\DateTime;
use Psl\DateTime\Duration;
use Psl\SMTP\Client\SendConfiguration;
use Psl\SMTP\DeliverBy;
use Psl\SMTP\DeliverByMode;
use Psl\SMTP\Priority;

// Per-send parameters control MAIL FROM extensions
$config = new SendConfiguration()
    // DSN: request delivery status notifications (RFC 3461)
    ->withDsnReturn('FULL')
    ->withDsnEnvelopeId('msg-001')
    ->withDsnNotify('SUCCESS,FAILURE')
    // Require TLS for the entire delivery chain (RFC 8689)
    ->withRequireTls(true)
    // Message priority (RFC 6710 / STANAG 4406)
    ->withPriority(Priority::Urgent)
    // Delivery deadline: return if not delivered within 2 hours (RFC 2852)
    ->withDeliverBy(new DeliverBy(Duration::hours(2), DeliverByMode::Return))
    // Deferred delivery: hold for 30 minutes (RFC 4865)
    ->withFutureRelease(Duration::minutes(30));

// Or hold until a specific time
$config = $config->withFutureRelease(DateTime::now()->plusHours(6));
```

## Partial Recipient Success

By default, the transport throws on the first rejected recipient. Enable `allowPartialSuccess` to deliver to accepted recipients and report failures via `DeliveryReport`.

```php
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Part;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Security;

// Enable partial success to deliver to accepted recipients
// even when some are rejected
$transport = new Transport(
    TransportConfiguration::default()
        ->withHost('smtp.example.com')
        ->withSecurity(Security::TLS)
        ->withAllowPartialSuccess(true),
);

$sender = new Mailbox('noreply', 'example.com');
$message = new Message()
    ->withFrom($sender)
    ->withTo('alice@example.com, bob@invalid.test, carol@example.com')
    ->withSubject('Team Update')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello team!')));

$report = $transport->send(Envelope::fromMessage($message), $message);

// Check for rejected recipients
if ($report->hasRejections()) {
    foreach ($report->rejectedRecipients as [$mailbox, $reply]) {
        // $mailbox->address: "bob@invalid.test"
        // $reply->code: 550
        // $reply->message: "User not found"
        IO\write_line($reply->message);
    }
}

$transport->close();
```

## Async and Cancellation

The transport is fully async-capable. Use `Async\concurrently` to send multiple messages in parallel with automatic connection pooling. Use cancellation tokens for timeouts.

```php
use Psl\Async;
use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Part;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Security;
use Psl\Vec;

$transport = new Transport(
    TransportConfiguration::default()->withHost('smtp.example.com')->withSecurity(Security::TLS),
);

$sender = new Mailbox('noreply', 'example.com');
$recipients = ['alice@example.com', 'bob@example.com', 'carol@example.com'];

// Send to all recipients concurrently, reusing pooled connections
Async\concurrently(Vec\map($recipients, static fn(string $address): Closure => static function () use (
    $transport,
    $sender,
    $address,
): void {
    $recipient = Mailbox::parse($address);
    $message = new Message()
        ->withFrom($sender)
        ->withTo($recipient)
        ->withSubject('Hello!')
        ->withContent(new Part\Text(new IO\MemoryHandle('Hi there!')));

    $transport->send(Envelope::fromMessage($message), $message);
}));

// Send with a timeout using a cancellation token
$message = new Message()
    ->withFrom($sender)
    ->withTo('dave@example.com')
    ->withSubject('Urgent')
    ->withContent(new Part\Text(new IO\MemoryHandle('Time-sensitive!')));

$transport->send(
    Envelope::fromMessage($message),
    $message,
    cancellation: new Async\TimeoutCancellationToken(Psl\DateTime\Duration::seconds(10)),
);

$transport->close();
```

## Low-Level Connection

`Connection` implements `Network\StreamInterface` and speaks raw SMTP commands. Use it for direct protocol interaction or to build custom transports.

```php
use Psl\SMTP\Client\Connection;
use Psl\SMTP\Command;
use Psl\TCP;

// Low-level connection for direct SMTP protocol interaction
$stream = TCP\connect('smtp.example.com', 25);
$connection = new Connection($stream);

// Read the server greeting
$greeting = $connection->readGreeting();
// $greeting->code: 220
// $greeting->message: "smtp.example.com ESMTP"

// EHLO to discover capabilities
$ehlo = $connection->ehlo('client.example.com');

// Check capabilities
$connection->supportsCapability('PIPELINING'); // bool
$connection->supportsCapability('8BITMIME'); // bool
$connection->getCapabilityValue('AUTH'); // "PLAIN LOGIN" or null
$connection->maxSize; // int or null

// Send arbitrary commands
$reply = $connection->sendCommand(new Command('NOOP'));
// $reply->code: 250
// $reply->isPositiveCompletion(): true

// Or send without reading reply (for pipelining)
$connection->writeCommand(new Command('NOOP'));
$reply = $connection->readReply();

$connection->close();
```

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

See [src/Psl/SMTP/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/smtp/src/Psl/SMTP/) for the full API.
