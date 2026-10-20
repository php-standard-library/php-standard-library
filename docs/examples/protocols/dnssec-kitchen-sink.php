<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

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

// 5. Route static names locally and other names to validated public DNS
$base = new DNS\SplitHorizonResolver(routes: [
    new DNS\Route(['db.internal', 'cache.internal'], $static),
], default: $public);

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
