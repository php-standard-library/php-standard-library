# MIME

The `MIME` component implements the Multipurpose Internet Mail Extensions (RFC 2045-2049) and related standards. It provides media type parsing, MIME part construction, multipart body streaming, content sniffing, S/MIME cryptography, and DKIM signing.

## Media Types

`MediaType` is an immutable value object representing a MIME media type like `application/json` or `text/html; charset=utf-8`. Type and subtype are always lowercased. Structured syntax suffixes and registration trees are extracted automatically.

@example('protocols/mime-media-type.php')

## Content Negotiation

`MediaPreferences` parses HTTP Accept headers and negotiates the best media type per RFC 9110.

@example('protocols/mime-negotiation.php')

## Parts

Parts are the building blocks of MIME messages. Every part implements `PartInterface`, which exposes `$mediaType`, `$headers`, and `body()`.

@example('protocols/mime-parts.php')

## Multipart Bodies

Multipart types compose multiple parts into a single body, delimited by a boundary string. All multipart types implement `MultiPartInterface` (which extends `PartInterface`), so they nest naturally.

@example('protocols/mime-multipart.php')

### Form Data

`Form` builds multipart/form-data bodies per RFC 7578, for HTML form submissions and file uploads. Parts added with `addPart()` get a `form-data` disposition that keeps the part's filename, and are sent as raw bytes: RFC 7578 deprecates Content-Transfer-Encoding, so the base64 or quoted-printable encoding of `Part\Data` and `Part\Text` is undone.

@example('protocols/mime-form.php')

### Streaming Parser

The streaming parser yields parts lazily from a read handle, with configurable limits to prevent abuse.

@example('protocols/mime-parser.php')

## S/MIME Cryptography

Sign, verify, encrypt, and decrypt using CMS (RFC 5652). Chain verification requires explicit trust anchors and uses OpenSSL to check certificate validity, purpose, and chains through embedded intermediates. An empty trust list rejects every certificate, including self-signed certificates. Pass `verifyCertificateChain: false` only to check the signature without establishing trust.

Verification uses OpenSSL to select the signer certificate using X.509 name comparison rules. It writes certificates and signer metadata to private temporary directories and removes them after each check. Plaintext stays in memory. Signing, encryption, and decryption need no temporary files.

@example('protocols/mime-smime.php')

## DKIM Signing

Sign raw MIME messages with DKIM (RFC 6376) using RSA-SHA256 or Ed25519-SHA256 (RFC 8463). RSA keys below 1024 bits are rejected per RFC 8301.

@example('protocols/mime-dkim.php')

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

See `src/Psl/MIME/` for the full API.

Received message and multipart headers may use whitespace before the colon. The parsers skip fields with invalid names or CR, LF, or NUL in unfolded values. `Headers::fromPairs()` and `Headers::with()` stay strict. `Parameters::parse()` reports unsafe decoded values with `ParameterParsingException`.
