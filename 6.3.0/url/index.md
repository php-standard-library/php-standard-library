# URL

The `URL` component provides a strict URL type that guarantees a scheme and authority are always present. It builds on top of the `URI` and `IRI` packages, adding validation and default port stripping.

## Parsing

Parse a URL string with strict validation; scheme and authority are required:

```php
use Psl\IO;
use Psl\URL;

$url = URL\parse('https://example.com:443/path?q=1#frag');

// "https"
IO\write_line('%s', $url->scheme);
// "example.com"
IO\write_line('%s', $url->authority->host->toString());
// null - default port stripped
IO\write_line('%s', $url->authority->port === null ? 'null' : (string) $url->authority->port);
// "/path"
IO\write_line('%s', $url->path);
// "q=1"
IO\write_line('%s', $url->query ?? '<unknown>');
// "frag"
IO\write_line('%s', $url->fragment ?? '<unknown>');

// "https://example.com/path?q=1#frag"
IO\write_line('%s', $url->toString());
```

Default ports are automatically stripped for known schemes (HTTP 80, HTTPS 443, FTP 21, SSH 22, and many more).

## From URI

Convert a parsed `URI` to a `URL` with validation:

```php
use Psl\IO;
use Psl\URI;
use Psl\URL;

$uri = URI\parse('https://example.com/path');
$url = URL\from_uri($uri);

// "https://example.com/path"
IO\write_line('%s', $url->toString());
```

Throws `InvalidURLException` if the URI is missing a scheme, authority, or has a rootless path.

## From IRI

Convert an internationalized IRI directly to a URL, applying Punycode encoding and percent-encoding in one step:

```php
use Psl\IO;
use Psl\IRI;
use Psl\URL;

$iri = IRI\parse('https://münchen.de/straße');
$url = URL\from_iri($iri);

// "https://xn--mnchen-3ya.de/stra%C3%9Fe"
IO\write_line('%s', $url->toString());
```

## Converting Back to URI

A `URL` can be converted back to a `URI` for use with the full URI API:

```php
use Psl\IO;
use Psl\URL;

$url = URL\parse('https://example.com/path');
$uri = $url->toURI();

// "https://example.com/path"
IO\write_line('%s', $uri->toString());
```

See [src/Psl/URL/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/url/src/Psl/URL/) for the full API.
