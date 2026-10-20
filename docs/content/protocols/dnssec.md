# DNSSEC

The `DNSSEC` component adds full DNSSEC validation to DNS resolution. It is a standalone package (`php-standard-library/dnssec`) that requires a `Psl\DNS\ResolverInterface` instance from the `psl/dns` package.

DNSSEC validation verifies that DNS responses have not been tampered with by checking cryptographic signatures (RRSIG), walking trust chains from the DNS root (DS/DNSKEY), and validating authenticated denial of existence proofs (NSEC/NSEC3).

## Getting Started

To use DNSSEC, you need two things: a DNS resolver that requests DNSSEC records (`dnssec: true`), and the DNSSEC validation layer that wraps it.

@example('protocols/dnssec-basic.php')

## RRSIG: Signature Verification

Every DNSSEC-signed response includes RRSIG records alongside the answer records. Each RRSIG contains a cryptographic signature over a set of records, the signer's identity, and the key tag to locate the corresponding DNSKEY.

`SecureResolver` requires signature coverage for each answer RRset in a signed zone, including every CNAME or DNAME in the query's alias chain. It checks each RRset with the keys of the zone that owns it. A parent zone's key cannot sign data below a signed child delegation. Missing signatures raise `InvalidProofException`; invalid or expired signatures raise `SignatureFailedException`.

A signed DNAME also proves an unsigned CNAME when the CNAME matches the DNAME substitution. The resolver rejects wildcard-expanded DNAMEs, alias loops, and substitutions longer than the DNS name limit. The resolver strips unrelated answer RRsets and their signatures so callers receive only the queried data and its alias chain. Each RRset uses class IN; the DNS wire decoder rejects other record classes except the EDNS OPT pseudo-record.

`StaticTrustChainResolver` uses the nearest configured ancestor zone for names below a zone apex. A more specific entry takes precedence, including an unsigned delegation.

## DS/DNSKEY: Trust Chain

DNSSEC trust is established through a chain from the DNS root to the target zone. Each parent zone contains a DS (Delegation Signer) record that is a cryptographic hash of the child zone's DNSKEY. By verifying each link in the chain, `TrustChainResolver` proves that the zone's keys are authorized by the parent, all the way up to the root trust anchor.

DS denial marks a name Insecure only when it proves an unsigned delegation. Names within a signed zone, including empty nonterminals, keep the parent zone's validated keys.

The walk for `example.com` goes: root (.) DNSKEY > `com.` DS > `com.` DNSKEY > `example.com.` DS > `example.com.` DNSKEY.

## NSEC/NSEC3: Authenticated Denial

When a domain does not exist (NXDOMAIN), the server must prove it. NSEC records list the names that DO exist in sorted order, proving there is no name between them. NSEC3 records do the same but with hashed names to prevent zone enumeration.

`SecureResolver` checks every NSEC, NSEC3, and SOA RRset in a signed response with the queried zone's keys. Each RRset needs at least one valid signature with the same owner and covered type. Only verified records reach the denial checks. Missing proof records raise `UnsignedResponseException` or `InvalidProofException`; unsigned or invalid proof RRsets raise `SignatureFailedException`.

## Trust Anchors

`TrustChainResolver` uses both IANA root keys, KSK-2017 (20326) and KSK-2024 (38696), as its trust anchor by default. For fully offline environments, `StaticTrustChainResolver` accepts pre-loaded DNSKEY records per zone, bypassing all network queries for trust chain resolution.

@example('protocols/dnssec-trust-anchor.php')

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

@example('protocols/dnssec-caching.php')

## Kitchen Sink

Compose DNSSEC validation with static entries, hosts file, search domains, split-horizon routing, multiple racing DNS-over-TLS nameservers, and caching. DNSSEC validation only wraps the public DNS path; trusted sources (static, internal, hosts file) are outside the validation boundary.

@example('protocols/dnssec-kitchen-sink.php')

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

See `src/Psl/DNSSEC/` for the full API.
