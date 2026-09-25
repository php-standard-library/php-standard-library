# Punycode

The `Punycode` component provides RFC 3492 Punycode encoding and decoding, the standard algorithm for representing Unicode strings in the ASCII-only subset used by internationalized domain names (IDNA).

## Usage

```php
use Psl\IO;
use Psl\Punycode;

// Encode Unicode to Punycode
$encoded = Punycode\encode('münchen');
// "mnchen-3ya"
IO\write_line('%s', $encoded);

// Decode Punycode to Unicode
$decoded = Punycode\decode('mnchen-3ya');
// "münchen"
IO\write_line('%s', $decoded);

// Round-trip
$encoded = Punycode\encode('日本語');
$decoded = Punycode\decode($encoded);
// "日本語"
IO\write_line('%s', $decoded);
```

## Standards

| RFC | Title |
|-----|-------|
| [RFC 3492](https://datatracker.ietf.org/doc/html/rfc3492) | Punycode: A Bootstring Encoding of Unicode for IDNA |

See [src/Psl/Punycode/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/punycode/src/Psl/Punycode/) for the full API.
