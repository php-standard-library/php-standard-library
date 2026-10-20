<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\DateTime\Duration;
use Psl\DNS;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\Record\DNSKEYRecord;
use Psl\DNSSEC;

$inner = new DNS\SystemResolver(dnssec: true);

// Default: uses IANA root KSK-2017 and KSK-2024, walks the DS/DNSKEY chain via DNS queries
$trustChain = new DNSSEC\TrustChainResolver($inner);

// Fully offline: pre-loaded DNSKEY records per zone, no network queries
$rootDnskey = new DNSKEYRecord('.', Duration::seconds(0), 257, 3, Algorithm::RSASHA256, '...');
$comDnskey = new DNSKEYRecord('com.', Duration::seconds(0), 257, 3, Algorithm::ECDSAP256SHA256, '...');

$offline = new DNSSEC\StaticTrustChainResolver([
    '.' => [$rootDnskey],
    'com.' => [$comDnskey],
]);

$resolver = new DNSSEC\SecureResolver($inner, $trustChain);
