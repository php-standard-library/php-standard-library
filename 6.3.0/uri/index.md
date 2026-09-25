# URI

The `URI` component provides RFC 3986 compliant parsing, normalization, reference resolution, and RFC 6570 URI Template expansion.

## Parsing

Parse any URI string into a structured, normalized `URI` object:

```php
use Psl\IO;
use Psl\URI;

$uri = URI\parse('https://Example.COM:443/foo/../bar?q=1#frag');

// "https"
IO\write_line('%s', $uri->scheme ?? '<unknown>');
// "example.com" - lowercased
IO\write_line('%s', $uri->authority?->host?->toString() ?? '<unknown>');
// 443
IO\write_line('%d', $uri->authority->port ?? 0);
// "/bar" - dot segments removed
IO\write_line('%s', $uri->path);
// "q=1"
IO\write_line('%s', $uri->query ?? '<unknown>');
// "frag"
IO\write_line('%s', $uri->fragment ?? '<unknown>');

// "https://example.com:443/bar?q=1#frag"
IO\write_line('%s', $uri->toString());
```

Normalization is applied eagerly: scheme and host are lowercased, percent-encoding is normalized (unreserved characters decoded, hex digits uppercased), and dot segments (`/../`, `/./`) are removed.

## Reference Resolution

Resolve relative references against a base URI per RFC 3986 Section 5:

```php
use Psl\IO;
use Psl\URI;

$base = URI\parse('https://example.com/a/b/c');

// "https://example.com/a/d"
IO\write_line('%s', URI\resolve($base, URI\parse('../d'))->toString());
// "https://other/x"
IO\write_line('%s', URI\resolve($base, URI\parse('//other/x'))->toString());
// "https://example.com/a/b/c?q=1"
IO\write_line('%s', URI\resolve($base, URI\parse('?q=1'))->toString());
```

## URI Templates

Parse and expand RFC 6570 URI Templates (Levels 1–4):

```php
use Psl\IO;
use Psl\URI\Template;

$template = Template\parse('https://api.example.com/users/{id}/repos{?sort,page}');

$uri = $template->expand([
    'id' => '42',
    'sort' => 'stars',
    'page' => '2',
]);

// "https://api.example.com/users/42/repos?sort=stars&page=2"
IO\write_line('%s', $uri->toString());
```

All operators are supported: simple `{var}`, reserved `{+var}`, fragment `{#var}`, label `{.var}`, path `{/var}`, parameter `{;var}`, query `{?var}`, and continuation `{&var}`. Modifiers include prefix `{var:3}` and explode `{var*}`.

## Authority & Hosts

The authority component is structured into user info, host, and port. Hosts are typed - either an IP address or a registered name:

```php
use Psl\IO;
use Psl\URI;

$uri = URI\parse('ssh://git@[::1%25eth0]:22/repo.git');

$authority = $uri->authority;

Psl\invariant($authority !== null, 'Invalid URI authority.');

// "git"
IO\write_line('%s', $authority->userInfo ?? '<unknown>');
// 22
IO\write_line('%d', $authority->port ?? 0);
// "[::1%25eth0]"
IO\write_line('%s', $authority->host->toString());
```

IPv6 addresses use RFC 5952 canonical form and support RFC 6874 zone identifiers.

## Standards

| RFC | Title |
|-----|-------|
| [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986) | Uniform Resource Identifier (URI): Generic Syntax |
| [RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570) | URI Template (Levels 1–4) |
| [RFC 6874](https://datatracker.ietf.org/doc/html/rfc6874) | IPv6 Zone Identifiers in URIs |
| [RFC 5952](https://datatracker.ietf.org/doc/html/rfc5952) | IPv6 Address Text Representation |

See [src/Psl/URI/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/uri/src/Psl/URI/) for the full API.
