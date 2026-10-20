# DNS

The `DNS` component provides async DNS resolution with connection pooling, EDNS0, DNS-over-TLS, DNS-over-HTTPS, system configuration detection, hosts file support, and search domain expansion. Resolvers can wrap one another to combine their features. Queries use non-blocking I/O; resolving a nameserver hostname uses a blocking system lookup.

## Basic Usage

`SystemResolver` mirrors your operating system's DNS behavior. It can be used as a default parameter value since it requires no arguments.

@example('protocols/dns-basic.php')

By default, `SystemResolver` uses plain UDP with TCP fallback for each nameserver discovered from the OS. You can encrypt all system DNS traffic with DNS-over-TLS by disabling UDP and passing a TLS-enabled connector. With `udp: false`, all queries go directly over TCP — no plaintext UDP is sent. Combined with a TLS connector, this turns all system nameserver communication into DNS-over-TLS (DoT, RFC 7858) while still using the OS-configured nameserver addresses.

@example('protocols/dns-system-dot.php')

## Resolver Types

Resolvers can be composed for different strategies: direct UDP/TCP, fallback on truncation, racing multiple nameservers, DNS-over-TLS, or DNS-over-HTTPS.

`FallbackResolver` tries the next resolver after a transport or protocol failure, SERVFAIL, or REFUSED. It returns NXDOMAIN at once because the response answers the query. It returns other response codes, including FORMERR, and propagates invalid input and cancellation. Use `SplitHorizonResolver` to route static names separately from public DNS.

`UDPResolver` supports IPv4 and IPv6 nameserver addresses and hostnames with IPv4 addresses. It resolves each hostname once per query through the system resolver, then uses that address to send the query and check the reply source. It checks the response question against the query name, type, and class. Name matching ignores case. It checks the transaction ID before decoding records. A wrong ID or question raises `ProtocolException`, so `FallbackResolver` can try TCP. Error replies may omit the question only when they contain no records.

`TCPResolver` also checks the response question and closes the connection if the name, type, or class does not match.

@example('protocols/dns-resolvers.php')

## Record Types

Over 20 DNS record types are supported. Responses provide typed access via `getAnswerRecords()` and `getFirstAnswerRecord()`. Response codes include `YXRRSET`, `NXRRSET`, and `BADCOOKIE`. Unknown codes raise `ProtocolException`.

@example('protocols/dns-records.php')

## Caching

`CachedResolver` wraps any resolver with TTL-aware caching. Positive answers use the lowest answer TTL. NXDOMAIN and NODATA responses require an SOA record and use the lower of its TTL and SOA MINIMUM, as defined by RFC 2308. CNAME records can shorten that TTL. Responses with a zero TTL, server errors, and negative responses without an SOA record are not cached.

Cookies and padding do not change the cache key. Other EDNS options, including Client Subnet, keep separate cache entries.

@example('protocols/dns-caching.php')

## Split-Horizon DNS

`SplitHorizonResolver` routes queries to different resolvers based on the domain name. Domain matching is suffix-based on domain boundaries.

@example('protocols/dns-split-horizon.php')

## Search Domains

`SearchDomainResolver` expands short names by appending search domains. Names with sufficient dots (configurable via `$numberOfDots`) are queried as-is.

@example('protocols/dns-search-domains.php')

## System Configuration

Load your OS DNS settings and hosts file directly. Both use async process execution for non-blocking reads.

@example('protocols/dns-system.php')

## EDNS0 Options

Pass EDNS0 options to any query for features like DNS cookies, client subnet hints, padding, and NSID. `ECSOption` rejects source and scope prefixes outside 0..32 for IPv4 or 0..128 for IPv6 when encoding.

`UDPResolver` and `FallbackResolver` return `BadCookie` to the caller. They do not retry with the server cookie. Per [RFC 7873 section 5.3](https://www.rfc-editor.org/rfc/rfc7873.html#section-5.3), callers can retry once with the returned server cookie after checking that the client cookie matches, or retry over TCP to the same nameserver. Bound retries to avoid a loop if the server keeps returning BADCOOKIE.

@example('protocols/dns-edns.php')

## DNS-over-HTTPS

`HTTPSResolver` sends queries over HTTPS (RFC 8484) using the HTTP client. HTTP/2 multiplexing allows concurrent queries to share a single connection. Pass your own `ClientInterface` to share connection pools across resolvers.

@example('protocols/dns-doh.php')

## Blocking System Resolver

`BlockingSystemResolver` wraps PHP's built-in `dns_get_record()` for synchronous DNS resolution with zero overhead. No event loop, no fibers, no connection pooling. Use it where async DNS is unnecessary. `SplitHorizonResolver` can route known names to a `StaticResolver` and all other names to this resolver.

Supports A, AAAA, CNAME, MX, NS, PTR, SOA, SRV, TXT, CAA, and NAPTR record types. EDNS0 options are ignored. Queries for unsupported types return NXDOMAIN.

@example('protocols/dns-blocking.php')

## HTTP Client Integration

`DNS\HTTP\Connector` is a connector decorator that resolves hostnames via any DNS resolver before delegating to an inner HTTP client connector. This enables fully async DNS resolution, DNS-over-TLS, DNS-over-HTTPS, DNSSEC validation, or custom resolution strategies for all HTTP requests.

When the origin host is already an IP address, no DNS query is performed. For HTTPS connections, the original hostname is preserved for TLS SNI so certificate validation works correctly even though the TCP connection targets the resolved IP.

@example('protocols/dns-http-connector.php')

## Kitchen Sink

Compose static entries, hosts file, search domains, split-horizon routing, multiple racing nameservers with DNS-over-TLS or DNS-over-HTTPS, TCP fallback, and caching into a single resolver that uses every feature.

@example('protocols/dns-kitchen-sink.php')

## Supported Record Types

| Type | RFC | Description |
|------|-----|-------------|
| A | RFC 1035 | IPv4 address |
| AAAA | RFC 3596 | IPv6 address |
| NS | RFC 1035 | Authoritative nameserver |
| CNAME | RFC 1035 | Canonical name alias |
| MX | RFC 1035 | Mail exchange |
| TXT | RFC 1035 | Text records (SPF, DKIM, etc.) |
| SRV | RFC 2782 | Service location |
| SOA | RFC 1035 | Start of authority |
| PTR | RFC 1035 | Reverse lookup pointer |
| CAA | RFC 8659 | Certificate authority authorization |
| SSHFP | RFC 4255 | SSH fingerprint |
| TLSA | RFC 6698 | TLS certificate association (DANE) |
| SVCB | RFC 9460 | Service binding |
| HTTPS | RFC 9460 | HTTPS service binding |
| LOC | RFC 1876 | Geographic location |
| NAPTR | RFC 3403 | Naming authority pointer |
| DS | RFC 4034 | DNSSEC delegation signer |
| DNSKEY | RFC 4034 | DNSSEC public key |
| RRSIG | RFC 4034 | DNSSEC signature |
| NSEC | RFC 4034 | DNSSEC authenticated denial |
| NSEC3 | RFC 5155 | DNSSEC hashed denial |

## System Configuration Sources

| OS | Source | Method |
|----|--------|--------|
| Linux/FreeBSD | `/etc/resolv.conf` | `cat` via Process |
| macOS | `scutil --dns` | Process (includes split DNS, custom ports) |
| Windows | `ipconfig /all` | Process |
| All platforms | hosts file | Process |

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 1035 | DNS wire format, A, NS, CNAME, MX, TXT, SOA, PTR records |
| RFC 2782 | SRV records |
| RFC 3403 | NAPTR records |
| RFC 3596 | AAAA records |
| RFC 4034 | DNSSEC record types (DS, DNSKEY, RRSIG, NSEC) |
| RFC 4255 | SSHFP records |
| RFC 5155 | NSEC3 and NSEC3PARAM records |
| RFC 6698 | TLSA records (DANE) |
| RFC 6891 | EDNS0 (OPT record, extended RCODE, UDP payload size) |
| RFC 7858 | DNS-over-TLS via TLS client configuration |
| RFC 8484 | DNS-over-HTTPS via HTTP client |
| RFC 7871 | EDNS Client Subnet option |
| RFC 7873 | DNS Cookies option |
| RFC 7830 | EDNS TCP Keepalive option |
| RFC 8467 | EDNS Padding option |
| RFC 8659 | CAA records |
| RFC 9460 | SVCB and HTTPS records |

See `src/Psl/DNS/` for the full API.
