# URI

The `URI` component provides RFC 3986 compliant parsing, normalization, reference resolution, and RFC 6570 URI Template expansion.

## Parsing

Parse any URI string into a structured, normalized `URI` object:

@example('networking/uri-parsing.php')

A relative path cannot contain a literal colon in its first segment. Add `./` before that segment, or encode the colon as `%3A`, as required by [RFC 3986 Section 4.2](https://datatracker.ietf.org/doc/html/rfc3986#section-4.2).

Normalization is applied eagerly: scheme and host are lowercased, percent-encoding is normalized (unreserved characters decoded, hex digits uppercased), and dot segments (`/../`, `/./`) are removed.

## Reference Resolution

Resolve relative references against a base URI per RFC 3986 Section 5:

@example('networking/uri-resolve.php')

## URI Templates

Parse and expand RFC 6570 URI Templates (Levels 1–4):

@example('networking/uri-template.php')

All operators are supported: simple `{var}`, reserved `{+var}`, fragment `{#var}`, label `{.var}`, path `{/var}`, parameter `{;var}`, query `{?var}`, and continuation `{&var}`. Modifiers include prefix `{var:3}` and explode `{var*}`.

## Authority & Hosts

The authority component is structured into user info, host, and port. Hosts are typed - either an IP address or a registered name:

@example('networking/uri-authority.php')

IPv6 addresses use RFC 5952 canonical form and support RFC 6874 zone identifiers.

## Standards

| RFC | Title |
|-----|-------|
| [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986) | Uniform Resource Identifier (URI): Generic Syntax |
| [RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570) | URI Template (Levels 1–4) |
| [RFC 6874](https://datatracker.ietf.org/doc/html/rfc6874) | IPv6 Zone Identifiers in URIs |
| [RFC 5952](https://datatracker.ietf.org/doc/html/rfc5952) | IPv6 Address Text Representation |

See `src/Psl/URI/` for the full API.
