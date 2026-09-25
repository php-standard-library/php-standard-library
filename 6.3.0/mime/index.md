# MIME

The `MIME` component implements the Multipurpose Internet Mail Extensions (RFC 2045-2049) and related standards. It provides media type parsing, MIME part construction, multipart body streaming, content sniffing, S/MIME cryptography, and DKIM signing - all without filesystem coupling or temporary files.

## Media Types

`MediaType` is an immutable value object representing a MIME media type like `application/json` or `text/html; charset=utf-8`. Type and subtype are always lowercased. Structured syntax suffixes and registration trees are extracted automatically.

```php
use Psl\MIME\MediaType;

$type = MediaType::parse('application/vnd.api+json; charset=utf-8');

$type->type; // "application"
$type->subtype; // "vnd.api+json"
$type->suffix; // "json"
$type->tree; // "vnd"
$type->parameters; // Parameters with charset=utf-8
$type->essence(); // "application/vnd.api+json"

// From file extension
$json = MediaType::fromExtension('json'); // application/json

// IANA registration
$type->isRegistered(); // true
$type->extensions(); // ["json"]
```

## Content Negotiation

`MediaPreferences` parses HTTP Accept headers and negotiates the best media type per RFC 9110.

```php
use Psl\MIME\MediaPreferences;
use Psl\MIME\MediaType;

$prefs = MediaPreferences::parse('text/html, application/json;q=0.9, */*;q=0.1');

$best = $prefs->negotiate([
    new MediaType('application', 'json'),
    new MediaType('text', 'html'),
]);

// $best->essence() === "text/html"
```

## Parts

Parts are the building blocks of MIME messages. Every part implements `PartInterface`, which exposes `$mediaType`, `$headers`, and `body()`.

```php
use Psl\IO;
use Psl\MIME\Headers;
use Psl\MIME\MediaType;
use Psl\MIME\Part;

// Raw part from headers + body
$raw = new Part\Part(Headers::fromPairs([['Content-Type', 'text/plain']]), new IO\MemoryHandle('Hello'));

// Text part with automatic transfer encoding (quoted-printable by default)
$text = new Part\Text(new IO\MemoryHandle('Hello, World!'));
$text->mediaType; // text/plain; charset=utf-8

// Binary attachment with base64 encoding
$data = new Part\Data(
    new IO\MemoryHandle('binary content'),
    filename: 'report.pdf',
    mediaType: MediaType::parse('application/pdf'),
);

// Convert attachment to inline with Content-ID
$inline = $data->asInline(Psl\MIME\ContentId::generate());
```

## Multipart Bodies

Multipart types compose multiple parts into a single body, delimited by a boundary string. All multipart types implement `MultiPartInterface` (which extends `PartInterface`), so they nest naturally.

```php
use Psl\IO;
use Psl\MIME\MediaType;
use Psl\MIME\MultiPart;
use Psl\MIME\Part;

// multipart/alternative: text + HTML versions
$alt = new MultiPart\Alternative();
$alt->addPart(new Part\Text(new IO\MemoryHandle('Plain text')));
$alt->addPart(new Part\Text(new IO\MemoryHandle('<h1>HTML</h1>'), subtype: 'html'));

// multipart/mixed: body + attachments
$mixed = new MultiPart\Composite($alt);
$mixed->addPart(
    new Part\Data(
        new IO\MemoryHandle('pdf content'),
        filename: 'doc.pdf',
        mediaType: MediaType::parse('application/pdf'),
    ),
);

// Nested multipart is just $mixed->body() - a streaming read handle
// IO\copy($mixed->body(), $outputHandle);
```

### Form Data

`Form` builds multipart/form-data bodies per RFC 7578, for HTML form submissions and file uploads.

```php
use Psl\IO;
use Psl\MIME\MediaType;
use Psl\MIME\MultiPart\Form;
use Psl\MIME\Part;

$form = new Form();
$form->addField('username', 'azjezz');
$form->addField('bio', 'PSL maintainer');
$form->addPart(
    'avatar',
    new Part\Data(new IO\MemoryHandle('image bytes'), filename: 'avatar.png', mediaType: MediaType::parse('image/png')),
);

$form->mediaType; // multipart/form-data; boundary=...
$form->boundary; // random 24-char alphanumeric string
```

### Streaming Parser

The streaming parser yields parts lazily from a read handle, with configurable limits to prevent abuse.

```php
use Psl\IO;
use Psl\MIME\MultiPart\Parser;

$body = "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n--boundary\r\nContent-Type: text/html\r\n\r\n<b>World</b>\r\n--boundary--\r\n";

$parser = new Parser(boundary: 'boundary', maxParts: 100, maxPartSize: 10_000_000, spoolThreshold: 2_097_152);

foreach ($parser->parse(new IO\MemoryHandle($body)) as $part) {
    IO\write_line('%s: %s', $part->mediaType->essence(), $part->body()->readAll());
}
```

## S/MIME Cryptography

Sign, verify, encrypt, and decrypt using CMS (RFC 5652) - entirely in-memory without temporary files or `openssl_pkcs7_*` functions.

```php
use Psl\MIME\SMIME;

// Signing requires a certificate and private key in PEM format.
// Verification can optionally check the certificate chain against trusted CAs.
// Encryption requires the recipient's certificate; decryption requires their private key.
// All operations are in-memory -- no temporary files or openssl_pkcs7_* calls.

/** @var string $certPem */
/** @var string $keyPem */
/** @var string $caCertPem */
/** @var string $recipientCertPem */
/** @var string $recipientKeyPem */

// Sign and verify
$signer = new SMIME\Signer($certPem, $keyPem);
$signed = $signer->sign('Hello');

$verifier = new SMIME\Verifier([$caCertPem]);
$result = $verifier->verify($signed, verifyCertificateChain: true);
$result->valid; // true
$result->content; // "Hello"
$result->digestAlgorithm; // DigestAlgorithm::Sha256

// Encrypt and decrypt
$encryptor = new SMIME\Encryptor([$recipientCertPem], SMIME\CipherAlgorithm::Aes256Cbc);
$encrypted = $encryptor->encrypt('Secret');

$decryptor = new SMIME\Decryptor($recipientKeyPem);
$decrypted = $decryptor->decrypt($encrypted); // "Secret"
```

## DKIM Signing

Sign raw MIME messages with DKIM (RFC 6376) using RSA-SHA256 or Ed25519-SHA256 (RFC 8463). RSA keys below 1024 bits are rejected per RFC 8301.

```php
use Psl\MIME\DKIM;

/** @var string $rsaPrivateKeyPem */

$config = new DKIM\SigningConfiguration(
    domain: 'example.com',
    selector: 'default',
    algorithm: DKIM\Algorithm::RsaSha256,
    headerCanonicalization: DKIM\Canonicalization::Relaxed,
    bodyCanonicalization: DKIM\Canonicalization::Relaxed,
);

$signer = new DKIM\Signer($rsaPrivateKeyPem, $config);

$rawMessage = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\n\r\nHello!";
$signedMessage = $signer->sign($rawMessage);

// $signedMessage now has a DKIM-Signature header prepended
```

## Content Sniffing

Detect MIME types from content bytes using magic signatures, RIFF sub-formats, ISO BMFF brands, and text heuristics. Works on raw byte strings or seekable read handles.

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 2045 | Media types, parameters, transfer encoding |
| RFC 2046 | Multipart bodies, boundary rules |
| RFC 2183 | Content-Disposition with safe filename |
| RFC 2231 | Parameter encoding with continuations and charset |
| RFC 2387 | multipart/related |
| RFC 2392 | Content-ID parsing and generation |
| RFC 5322 | Header line folding |
| RFC 5652 | CMS signing, verification, encryption, decryption |
| RFC 6376 | DKIM signing with simple/relaxed canonicalization |
| RFC 6838 | Media type registration, suffix/tree extraction |
| RFC 7578 | multipart/form-data |
| RFC 8301 | DKIM minimum key size enforcement |
| RFC 8463 | Ed25519-SHA256 DKIM signing |
| RFC 8551 | S/MIME 4.0 message specification |
| RFC 9110 | Content negotiation (Accept header) |

See [src/Psl/MIME/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/mime/src/Psl/MIME/) for the full API.
