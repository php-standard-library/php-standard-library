# DNS

The `DNS` component provides async DNS resolution with connection pooling, EDNS0, DNS-over-TLS, DNS-over-HTTPS, system configuration detection, hosts file support, and search domain expansion. All resolvers are non-blocking and composable via the decorator pattern.

## Basic Usage

`SystemResolver` mirrors your operating system's DNS behavior. It can be used as a default parameter value since it requires no arguments.

```php
use Psl\DNS;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\RecordType;

// System resolver mirrors your OS DNS settings
$resolver = new DNS\SystemResolver();

// Query A records
$response = $resolver->query('example.com', RecordType::A);

// Check the result
if ($response->code->isSuccess()) {
    foreach ($response->getAnswerRecords(ARecord::class) as $record) {
        $record->address; // Psl\IP\Address
    }
}
```

By default, `SystemResolver` uses plain UDP with TCP fallback for each nameserver discovered from the OS. You can encrypt all system DNS traffic with DNS-over-TLS by disabling UDP and passing a TLS-enabled connector. With `udp: false`, all queries go directly over TCP — no plaintext UDP is sent. Combined with a TLS connector, this turns all system nameserver communication into DNS-over-TLS (DoT, RFC 7858) while still using the OS-configured nameserver addresses.

```php
use Psl\DNS;
use Psl\TCP;
use Psl\TLS;

// Encrypt all system DNS traffic with DNS-over-TLS.
// udp: false ensures no plaintext UDP queries are sent.
// The TLS connector wraps every TCP connection in TLS (DoT, RFC 7858).
// Nameserver addresses are still read from the OS configuration.
$resolver = new DNS\SystemResolver(
    udp: false,
    connector: new TLS\TCPConnector(new TCP\Connector(), new TLS\Connector(new TLS\ClientConfiguration())),
);
```

## Resolver Types

Resolvers can be composed for different strategies: direct UDP/TCP, fallback on truncation, racing multiple nameservers, DNS-over-TLS, or DNS-over-HTTPS.

```php
use Psl\DNS;
use Psl\TCP;
use Psl\TLS;

// UDP resolver (standard DNS, port 53)
$udp = new DNS\UDPResolver('8.8.8.8');

// TCP resolver with connection pooling
$tcp = new DNS\TCPResolver('8.8.8.8');

// DNS-over-TLS (port 853)
$dot = new DNS\TCPResolver(
    '8.8.8.8',
    port: 853,
    connector: new TLS\TCPConnector(new TCP\Connector(), new TLS\Connector(new TLS\ClientConfiguration())),
);

// DNS-over-HTTPS (RFC 8484)
$doh = new DNS\HTTPSResolver('https://1.1.1.1/dns-query');

// UDP with automatic TCP fallback on truncated responses
$fallback = new DNS\FallbackResolver([$udp, $tcp]);

// Race multiple nameservers, return the fastest response
$racing = new DNS\RacingResolver([
    new DNS\UDPResolver('8.8.8.8'),
    new DNS\UDPResolver('1.1.1.1'),
]);
```

## Record Types

Over 20 DNS record types are supported. Responses provide typed access via `getAnswerRecords()` and `getFirstAnswerRecord()`.

```php
use Psl\DNS;
use Psl\DNS\Record;
use Psl\IP\Address;

$resolver = new DNS\SystemResolver();

// Query different record types
$a = $resolver->query('example.com', Record\RecordType::A);
$aaaa = $resolver->query('example.com', Record\RecordType::AAAA);
$mx = $resolver->query('example.com', Record\RecordType::MX);
$txt = $resolver->query('example.com', Record\RecordType::TXT);
$srv = $resolver->query('_http._tcp.example.com', Record\RecordType::SRV);

// Extract typed records from a response
foreach ($a->getAnswerRecords(Record\ARecord::class) as $record) {
    $record->name; // "example.com"
    $record->duration; // TTL as Duration
    $record->address; // Psl\IP\Address
}

foreach ($mx->getAnswerRecords(Record\MXRecord::class) as $record) {
    $record->preference; // int
    $record->exchange; // "mail.example.com"
}

// Reverse lookup
$ptr = $resolver->reverseQuery(Address::parse('8.8.8.8'));
foreach ($ptr->getAnswerRecords(Record\PTRRecord::class) as $record) {
    $record->target; // "dns.google"
}
```

## Caching

`CachedResolver` wraps any resolver with TTL-aware caching. Cache TTL is derived from the minimum record TTL in each response.

```php
use Psl\Cache;
use Psl\DNS;

// Wrap any resolver with caching
$resolver = new DNS\CachedResolver(new DNS\SystemResolver(), new Cache\LocalStore());

// First query hits the network
$response = $resolver->query('example.com', DNS\Record\RecordType::A);

// Second query returns from cache (TTL-aware)
$cached = $resolver->query('example.com', DNS\Record\RecordType::A);
```

## Split-Horizon DNS

`SplitHorizonResolver` routes queries to different resolvers based on the domain name. Domain matching is suffix-based on domain boundaries.

```php
use Psl\DNS;

// Route queries for internal domains to a private nameserver,
// everything else to a public resolver
$resolver = new DNS\SplitHorizonResolver(routes: [
    new DNS\Route(domains: ['corp.internal', 'dev.internal'], resolver: new DNS\UDPResolver('10.0.0.53')),
], default: new DNS\SystemResolver());

// Uses the private nameserver (matches corp.internal)
$resolver->query('db.corp.internal', DNS\Record\RecordType::A);

// Uses the system resolver (no matching route)
$resolver->query('example.com', DNS\Record\RecordType::A);
```

## Search Domains

`SearchDomainResolver` expands short names by appending search domains. Names with sufficient dots (configurable via `$numberOfDots`) are queried as-is.

```php
use Psl\DNS;
use Psl\DNS\Record\RecordType;

// Expand short names by appending search domains
$resolver = new DNS\SearchDomainResolver(
    inner: new DNS\SystemResolver(),
    searchDomains: ['example.com', 'corp.example.com'],
);

// Queries "db.example.com", then "db.corp.example.com" if NXDOMAIN
$resolver->query('db', RecordType::A);

// Fully qualified names skip expansion
$resolver->query('google.com', RecordType::A);
```

## System Configuration

Load your OS DNS settings and hosts file directly. Both use async process execution for non-blocking reads.

```php
use Psl\DNS\System\HostsFile\HostsFile;
use Psl\DNS\System\Settings;

// Load system DNS settings (nameservers, search domains)
$settings = Settings::load();
foreach ($settings->nameservers as $ns) {
    $ns->host; // "8.8.8.8"
    $ns->port; // 53
    $ns->forDomains; // [] for global, ["corp.internal"] for scoped
}

$settings->searchDomains; // ["example.com", "corp.example.com"]

// Load and query the hosts file
$hosts = HostsFile::load();
$addresses = $hosts->lookup('localhost');

// [Address("127.0.0.1"), Address("::1")]
```

## EDNS0 Options

Pass EDNS0 options to any query for features like DNS cookies, client subnet hints, padding, and NSID.

```php
use Psl\DNS;
use Psl\DNS\EDNS;
use Psl\DNS\Record\RecordType;
use Psl\IP;
use Psl\SecureRandom;

$resolver = new DNS\SystemResolver();

// Query with EDNS0 options
$response = $resolver->query('example.com', RecordType::A, ednsOptions: [
    new EDNS\CookieOption(clientCookie: SecureRandom\bytes(8)),
    new EDNS\ECSOption(IP\Address::parse('203.0.113.0'), sourcePrefixLength: 24, scopePrefixLength: 0),
    new EDNS\PaddingOption(128),
    new EDNS\NSIDOption(),
]);
```

## DNS-over-HTTPS

`HTTPSResolver` sends queries over HTTPS (RFC 8484) using the HTTP client. HTTP/2 multiplexing allows concurrent queries to share a single connection. Pass your own `ClientInterface` to share connection pools across resolvers.

```php
use Psl\Cache;
use Psl\DNS;
use Psl\HTTP\Client;

// Default client — creates one internally
$resolver = new DNS\HTTPSResolver('https://1.1.1.1/dns-query');

// Bring your own client for shared connection pooling
$client = new Client\Client();
$cloudflare = new DNS\HTTPSResolver('https://1.1.1.1/dns-query', $client);
$google = new DNS\HTTPSResolver('https://dns.google/dns-query', $client);

// Race two DoH providers
$racing = new DNS\RacingResolver([$cloudflare, $google]);

// Compose with caching
$cache = new Cache\LocalStore();
$cached = new DNS\CachedResolver($racing, $cache);

$response = $cached->query('example.com', DNS\Record\RecordType::A);
```

## Blocking System Resolver

`BlockingSystemResolver` wraps PHP's built-in `dns_get_record()` for synchronous DNS resolution with zero overhead. No event loop, no fibers, no connection pooling. Useful as a lightweight fallback behind a `StaticResolver`, or in environments where async DNS is unnecessary.

Supports A, AAAA, CNAME, MX, NS, PTR, SOA, SRV, TXT, CAA, and NAPTR record types. EDNS0 options are ignored. Queries for unsupported types return NXDOMAIN.

```php
use Psl\DNS;
use Psl\DNS\Record\RecordType;

// BlockingSystemResolver wraps PHP's dns_get_record() for synchronous,
// zero-overhead DNS resolution. No event loop, no fibers, no connection pooling.
$resolver = new DNS\BlockingSystemResolver();

// Query A records
$response = $resolver->query('example.com', RecordType::A);
foreach ($response->answers as $record) {
    /** @var DNS\Record\ARecord $record */
    $record->address->toString();
    $record->duration->getSeconds();
}

// Query MX records
$response = $resolver->query('example.com', RecordType::MX);
foreach ($response->answers as $record) {
    /** @var DNS\Record\MXRecord $record */
    $record->exchange;
    $record->preference;
}

// Non-existent domain returns NXDOMAIN, never throws
$response = $resolver->query('nonexistent.invalid', RecordType::A);
$response->code; // ResponseCode::NonExistentDomain

// Reverse lookup via PTR
$response = $resolver->reverseQuery(Psl\IP\Address::parse('8.8.8.8'));
foreach ($response->answers as $record) {
    /** @var DNS\Record\PTRRecord $record */
    $record->target; // "dns.google"
}
```

## HTTP Client Integration

`DNS\HTTP\Connector` is a connector decorator that resolves hostnames via any DNS resolver before delegating to an inner HTTP client connector. This enables fully async DNS resolution, DNS-over-TLS, DNS-over-HTTPS, DNSSEC validation, or custom resolution strategies for all HTTP requests.

When the origin host is already an IP address, no DNS query is performed. For HTTPS connections, the original hostname is preserved for TLS SNI so certificate validation works correctly even though the TCP connection targets the resolved IP.

```php
use Psl\Cache;
use Psl\DNS;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// Build a resolver chain: cached async UDP resolution with TCP fallback on truncation.
$resolver = new DNS\CachedResolver(new DNS\FallbackResolver([
    new DNS\UDPResolver(host: '1.1.1.1'),
    new DNS\TCPResolver(host: '1.1.1.1'),
]), new Cache\LocalStore());

// Wrap the pooled connector with DNS resolution.
// All HTTP requests will resolve hostnames through the resolver above,
// benefiting from caching, async I/O, and custom nameserver selection.
$connector = new DNS\HTTP\Connector(new Client\Connection\PooledConnector(), $resolver);

$client = new Client\Client(connector: $connector);

$tx = $client->send(new Message\Request(method: 'GET', url: URL\parse('https://example.com')));

$tx->response->status;
$tx->response->body?->readAll();
```

## Kitchen Sink

Compose static entries, hosts file, search domains, split-horizon routing, multiple racing nameservers with DNS-over-TLS or DNS-over-HTTPS, TCP fallback, and caching into a single resolver that uses every feature.

```php
use Psl\Cache;
use Psl\DateTime\Duration;
use Psl\DNS;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\System\HostsFile\HostsFile;
use Psl\IO;
use Psl\IP\Address;
use Psl\TCP;
use Psl\TLS;

$cache = new Cache\LocalStore();

// 1. Static entries for internal services (never hit the network)
$static = new DNS\StaticResolver([
    'db.internal' => [
        RecordType::A->value => [
            new ARecord('db.internal', Duration::hours(999), Address::parse('10.0.0.5')),
        ],
    ],
    'cache.internal' => [
        RecordType::A->value => [
            new ARecord('cache.internal', Duration::hours(999), Address::parse('10.0.0.6')),
        ],
    ],
]);

// 2. Internal nameserver for corporate domains (split-horizon)
$internalDns = new DNS\FallbackResolver([
    new DNS\UDPResolver('10.0.0.53'),
    new DNS\TCPResolver('10.0.0.53'),
]);

// 3. Race multiple public nameservers for fastest response
$public = new DNS\RacingResolver([
    // Cloudflare DNS-over-TLS
    new DNS\FallbackResolver([
        new DNS\UDPResolver('1.1.1.1', dnssec: true),
        new DNS\TCPResolver(
            '1.1.1.1',
            port: 853,
            dnssec: true,
            connector: new TLS\TCPConnector(new TCP\Connector(), new TLS\Connector(new TLS\ClientConfiguration())),
        ),
    ]),
    // Google DNS-over-TLS
    new DNS\FallbackResolver([
        new DNS\UDPResolver('8.8.8.8', dnssec: true),
        new DNS\TCPResolver(
            '8.8.8.8',
            port: 853,
            dnssec: true,
            connector: new TLS\TCPConnector(new TCP\Connector(), new TLS\Connector(new TLS\ClientConfiguration())),
        ),
    ]),
    // Cloudflare secondary (plain TCP fallback)
    new DNS\FallbackResolver([
        new DNS\UDPResolver('1.0.0.1', dnssec: true),
        new DNS\TCPResolver('1.0.0.1', dnssec: true),
    ]),
    // Google secondary (plain TCP fallback)
    new DNS\FallbackResolver([
        new DNS\UDPResolver('8.8.4.4', dnssec: true),
        new DNS\TCPResolver('8.8.4.4', dnssec: true),
    ]),
]);

// 4. Static overrides first, then public nameservers
$base = new DNS\FallbackResolver([$static, $public]);

// 5. Route corporate domains to internal DNS, everything else to public
$routed = new DNS\SplitHorizonResolver(routes: [
    new DNS\Route(['corp.internal', 'dev.internal'], $internalDns),
], default: $base);

// 6. Expand short names using search domains
$searched = new DNS\SearchDomainResolver($routed, ['corp.internal', 'dev.internal']);

// 7. Check the hosts file before any network query
$hostsAware = new DNS\HostsFileResolver($searched, HostsFile::load());

// 8. Cache everything on top
$resolver = new DNS\CachedResolver($hostsAware, $cache);

// 9. Profit!
$response = $resolver->query('php-standard-library.dev', RecordType::A);

IO\write_line(
    'php-standard-library.dev -> %s',
    $response->getFirstAnswerRecord(ARecord::class)?->address->toString() ?? '<unknown>',
);
```

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

See [src/Psl/DNS/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/dns/src/Psl/DNS/) for the full API.
