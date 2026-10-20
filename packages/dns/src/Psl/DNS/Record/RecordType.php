<?php

declare(strict_types=1);

namespace Psl\DNS\Record;

/**
 * DNS resource record type numbers as assigned by IANA.
 *
 * @api
 */
enum RecordType: int
{
    /**
     * IPv4 address record (RFC 1035).
     */
    case A = 1;

    /**
     * Authoritative nameserver record (RFC 1035).
     */
    case NS = 2;

    /**
     * Canonical name (alias) record (RFC 1035).
     */
    case CNAME = 5;

    /**
     * Start of authority record (RFC 1035).
     */
    case SOA = 6;

    /**
     * Pointer record for reverse DNS lookups (RFC 1035).
     */
    case PTR = 12;

    /**
     * Mail exchange record (RFC 1035).
     */
    case MX = 15;

    /**
     * Text record (RFC 1035).
     */
    case TXT = 16;

    /**
     * IPv6 address record (RFC 3596).
     */
    case AAAA = 28;

    /**
     * Geographic location record (RFC 1876).
     */
    case LOC = 29;

    /**
     * Service locator record (RFC 2782).
     */
    case SRV = 33;

    /**
     * Naming Authority Pointer record (RFC 3403).
     */
    case NAPTR = 35;

    /**
     * Subtree redirection record (RFC 6672).
     */
    case DNAME = 39;

    /**
     * EDNS0 pseudo-record for extension mechanisms (RFC 6891).
     */
    case OPT = 41;

    /**
     * Delegation signer record for DNSSEC (RFC 4034).
     */
    case DS = 43;

    /**
     * SSH fingerprint record (RFC 4255).
     */
    case SSHFP = 44;

    /**
     * DNSSEC signature record (RFC 4034).
     */
    case RRSIG = 46;

    /**
     * Next secure record for DNSSEC denial of existence (RFC 4034).
     */
    case NSEC = 47;

    /**
     * DNS public key record for DNSSEC (RFC 4034).
     */
    case DNSKEY = 48;

    /**
     * Hashed next secure record for DNSSEC denial of existence (RFC 5155).
     */
    case NSEC3 = 50;

    /**
     * NSEC3 parameters record (RFC 5155).
     */
    case NSEC3PARAM = 51;

    /**
     * TLSA certificate association record for DANE (RFC 6698).
     */
    case TLSA = 52;

    /**
     * Service binding record (RFC 9460).
     */
    case SVCB = 64;

    /**
     * HTTPS service binding record (RFC 9460).
     */
    case HTTPS = 65;

    /**
     * Certification Authority Authorization record (RFC 8659).
     */
    case CAA = 257;
}
