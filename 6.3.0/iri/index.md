# IRI

The `IRI` component provides RFC 3987 compliant Internationalized Resource Identifier parsing with full Unicode support, Punycode encoding/decoding, and IDNA 2008 domain name processing.

## Parsing

Parse IRI strings containing Unicode characters:

```php
use Psl\IO;
use Psl\IRI;

$iri = IRI\parse('https://münchen.de/straße?q=ünited#§ion');

// "https"
IO\write_line('%s', $iri->scheme ?? '<unknown>');
// "münchen.de"
IO\write_line('%s', $iri->authority?->host?->toString() ?? '<unknown>');
// "/straße"
IO\write_line('%s', $iri->path);
// "q=ünited"
IO\write_line('%s', $iri->query ?? '<unknown>');
// "§ion"
IO\write_line('%s', $iri->fragment ?? '<unknown>');

// "https://münchen.de/straße?q=ünited#§ion"
IO\write_line('%s', $iri->toString());
```

Input is NFC-normalized and validated against RFC 3987 character ranges. Private-use characters are only permitted in the query component.

## Converting to URI

Convert an IRI to an ASCII-only RFC 3986 URI. International domain names are Punycode-encoded and Unicode path/query/fragment characters are percent-encoded:

```php
use Psl\IO;
use Psl\IRI;

$iri = IRI\parse('https://münchen.de/straße');

$uri = $iri->toURI();

// "https://xn--mnchen-3ya.de/stra%C3%9Fe"
IO\write_line('%s', $uri->toString());
```

## Converting from URI

Reverse the process - decode a URI back to an IRI with Unicode characters restored:

```php
use Psl\IO;
use Psl\IRI;
use Psl\URI;

$uri = URI\parse('https://xn--mnchen-3ya.de/stra%C3%9Fe');

$iri = IRI\from_uri($uri);

// "https://münchen.de/straße"
IO\write_line('%s', $iri->toString());
```

## Standards

| RFC | Title |
|-----|-------|
| [RFC 3987](https://datatracker.ietf.org/doc/html/rfc3987) | Internationalized Resource Identifiers (IRIs) |
| [RFC 3492](https://datatracker.ietf.org/doc/html/rfc3492) | Punycode: Bootstring Encoding for IDNA |
| [RFC 5891](https://datatracker.ietf.org/doc/html/rfc5891) | IDNA 2008: Protocol |
| [RFC 5892](https://datatracker.ietf.org/doc/html/rfc5892) | IDNA 2008: Unicode Code Points |

See [src/Psl/IRI/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/iri/src/Psl/IRI/) for the full API.
