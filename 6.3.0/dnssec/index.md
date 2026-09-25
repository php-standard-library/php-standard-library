# DNSSEC

The `DNSSEC` component adds full DNSSEC validation to DNS resolution. It is a standalone package (`php-standard-library/dnssec`) that requires a `Psl\DNS\ResolverInterface` instance from the `psl/dns` package.

DNSSEC validation verifies that DNS responses have not been tampered with by checking cryptographic signatures (RRSIG), walking trust chains from the DNS root (DS/DNSKEY), and validating authenticated denial of existence proofs (NSEC/NSEC3).

## Getting Started

To use DNSSEC, you need two things: a DNS resolver that requests DNSSEC records (`dnssec: true`), and the DNSSEC validation layer that wraps it.

```php
use Psl\DNS;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC;

// Create a DNSSEC-validating resolver
$inner = new DNS\SystemResolver(dnssec: true);
$trustChain = new DNSSEC\TrustChainResolver($inner);
$resolver = new DNSSEC\SecureResolver($inner, $trustChain);

// Queries are validated: signatures, trust chain, NSEC proofs
$response = $resolver->query('example.com', RecordType::A);

// Throws SignatureFailedException, BrokenTrustChainException,
// InvalidProofException, or UnsignedResponseException on failure
```

## RRSIG: Signature Verification

Every DNSSEC-signed response includes RRSIG records alongside the answer records. Each RRSIG contains a cryptographic signature over a set of records, the signer's identity, and the key tag to locate the corresponding DNSKEY.

When `SecureResolver` receives a response, it verifies that the RRSIG signatures are valid using the zone's public keys (DNSKEY records). If any signature is invalid or expired, `SignatureFailedException` is thrown.

## DS/DNSKEY: Trust Chain

DNSSEC trust is established through a chain from the DNS root to the target zone. Each parent zone contains a DS (Delegation Signer) record that is a cryptographic hash of the child zone's DNSKEY. By verifying each link in the chain, `TrustChainResolver` proves that the zone's keys are authorized by the parent, all the way up to the root trust anchor.

The walk for `example.com` goes: root (.) DNSKEY > `com.` DS > `com.` DNSKEY > `example.com.` DS > `example.com.` DNSKEY.

## NSEC/NSEC3: Authenticated Denial

When a domain does not exist (NXDOMAIN), the server must prove it. NSEC records list the names that DO exist in sorted order, proving there is no name between them. NSEC3 records do the same but with hashed names to prevent zone enumeration.

`SecureResolver` validates these proofs automatically. If the proof is missing or invalid, `InvalidProofException` is thrown.

## Trust Anchors

`TrustChainResolver` uses the IANA root KSK as its trust anchor by default. For fully offline environments, `StaticTrustChainResolver` accepts pre-loaded DNSKEY records per zone, bypassing all network queries for trust chain resolution.

```php
use Psl\DateTime\Duration;
use Psl\DNS;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNSSEC;

$inner = new DNS\SystemResolver(dnssec: true);

// Default: uses IANA root KSK, walks the DS/DNSKEY chain via DNS queries
$trustChain = new DNSSEC\TrustChainResolver($inner);

// Fully offline: pre-loaded DNSKEY records per zone, no network queries
$rootDnskey = new DNSKEYRecord('.', Duration::seconds(0), 257, 3, Algorithm::RSASHA256, '...');
$comDnskey = new DNSKEYRecord('com.', Duration::seconds(0), 257, 3, Algorithm::ECDSAP256SHA256, '...');

$offline = new DNSSEC\StaticTrustChainResolver([
    '.' => [$rootDnskey],
    'com.' => [$comDnskey],
]);

$resolver = new DNSSEC\SecureResolver($inner, $trustChain);
```

## Validation Errors

DNSSEC validation produces specific exceptions for each failure mode:

| Exception | Meaning |
|-----------|---------|
| `SignatureFailedException` | RRSIG verification failed or crypto format is invalid |
| `BrokenTrustChainException` | DS/DNSKEY trust chain is incomplete or invalid |
| `InvalidProofException` | NSEC/NSEC3 proof does not check out |
| `UnsignedResponseException` | Expected DNSSEC-signed data but response was unsigned |

All exceptions extend `Psl\DNSSEC\Exception\RuntimeException`.

## Caching

DNSSEC validation is expensive - each query triggers a trust chain walk from the root zone through every intermediate zone to the target. `CachedTrustChainResolver` caches the validated trust chain results so subsequent queries to the same TLD reuse the cached chain. Combine with `CachedResolver` on top to cache the final validated responses.

```php
use Psl\Cache;
use Psl\DNS;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC;

$cache = new Cache\LocalStore();

// The inner resolver must request DNSSEC records
$dnsResolver = new DNS\SystemResolver(dnssec: true);

// Cache trust chain results to avoid repeated root-to-leaf walks
// Without caching, every query triggers DS/DNSKEY lookups for each zone
$trustChain = new DNSSEC\CachedTrustChainResolver(new DNSSEC\TrustChainResolver($dnsResolver), $cache);

// SecureResolver validates every response using the cached trust chain
$secureResolver = new DNSSEC\SecureResolver($dnsResolver, $trustChain);

// Cache the validated DNS responses on top
$resolver = new DNS\CachedResolver($secureResolver, $cache);

// First query: full trust chain walk (root -> com. -> example.com.)
$resolver->query('example.com', RecordType::A);

// Second query for same TLD: trust chain for "com." is cached
$resolver->query('another.com', RecordType::A);

// Third query for same domain: entire response is cached
$resolver->query('example.com', RecordType::A);
```

## Kitchen Sink

Compose DNSSEC validation with static entries, hosts file, search domains, split-horizon routing, multiple racing DNS-over-TLS nameservers, and caching. DNSSEC validation only wraps the public DNS path; trusted sources (static, internal, hosts file) are outside the validation boundary.

```php
use Psl\Cache;
use Psl\DateTime\Duration;
use Psl\DNS;
use Psl\DNS\Record\ARecord;
use Psl\DNS\Record\RecordType;
use Psl\DNS\System\HostsFile\HostsFile;
use Psl\DNSSEC;
use Psl\IO;
use Psl\IP\Address;
use Psl\TCP;
use Psl\TLS;

$cache = new Cache\LocalStore();

// 1. Static entries (trusted by definition, no DNSSEC needed)
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

// 2. Internal nameserver for corporate domains (trusted network, no DNSSEC)
$internalDns = new DNS\FallbackResolver([
    new DNS\UDPResolver('10.0.0.53'),
    new DNS\TCPResolver('10.0.0.53'),
]);

// 3. Race multiple DNSSEC-enabled public nameservers
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

// 4. DNSSEC: validate public DNS responses only
$public = new DNSSEC\SecureResolver(
    $public,
    new DNSSEC\CachedTrustChainResolver(new DNSSEC\TrustChainResolver($public), $cache),
);

// 5. Static overrides first, then DNSSEC-validated public DNS
$base = new DNS\FallbackResolver([$static, $public]);

// 6. Route corporate domains to internal DNS (unvalidated),
//    everything else to static + DNSSEC-validated public
$routed = new DNS\SplitHorizonResolver(routes: [
    new DNS\Route(['corp.internal', 'dev.internal'], $internalDns),
], default: $base);

// 7. Expand short names using search domains
$searched = new DNS\SearchDomainResolver($routed, ['corp.internal', 'dev.internal']);

// 8. Check the hosts file before any network query
$hostsAware = new DNS\HostsFileResolver($searched, HostsFile::load());

// 9. Cache everything on top
$resolver = new DNS\CachedResolver($hostsAware, $cache);

// 10. Profit!
$response = $resolver->query('php-standard-library.dev', RecordType::A);

IO\write_line(
    'php-standard-library.dev -> %s',
    $response->getFirstAnswerRecord(ARecord::class)?->address->toString() ?? '<unknown>',
);
```

## Supported Algorithms

| Algorithm | RFC |
|-----------|-----|
| RSA/SHA-1 | RFC 4034 |
| RSA/SHA-256 | RFC 5702 |
| RSA/SHA-512 | RFC 5702 |
| ECDSA P-256/SHA-256 | RFC 6605 |
| ECDSA P-384/SHA-384 | RFC 6605 |
| Ed25519 | RFC 8080 |
| Ed448 | RFC 8080 |

## RFC Compliance

| RFC | Coverage |
|-----|----------|
| RFC 4033 | DNSSEC introduction and requirements |
| RFC 4034 | DNSSEC resource records (RRSIG, DNSKEY, DS, NSEC) |
| RFC 4035 | DNSSEC protocol modifications and validation |
| RFC 5155 | NSEC3 hashed authenticated denial of existence |
| RFC 5702 | RSA/SHA-256 and RSA/SHA-512 for DNSSEC |
| RFC 6605 | ECDSA for DNSSEC (P-256/SHA-256, P-384/SHA-384) |
| RFC 8080 | Edwards-curve algorithms for DNSSEC (Ed25519, Ed448) |

See [src/Psl/DNSSEC/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/dnssec/src/Psl/DNSSEC/) for the full API.
