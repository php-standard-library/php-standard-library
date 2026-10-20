<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

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

// 4. Route static names locally and other names to public DNS
$base = new DNS\SplitHorizonResolver(routes: [
    new DNS\Route(['db.internal', 'cache.internal'], $static),
], default: $public);

// 5. Route corporate domains to internal DNS, everything else to public
$routed = new DNS\SplitHorizonResolver(routes: [
    new DNS\Route(['corp.internal', 'dev.internal'], $internalDns),
], default: $base);

// 6. Expand short names using search domains
$searched = new DNS\SearchDomainResolver($routed, ['corp.internal', 'dev.internal']);

// 7. Check the hosts file before any network query
$hostsAware = new DNS\HostsFileResolver($searched, HostsFile::load());

// 8. Cache positive and negative answers with a positive TTL
$resolver = new DNS\CachedResolver($hostsAware, $cache);

// 9. Profit!
$response = $resolver->query('php-standard-library.dev', RecordType::A);

IO\write_line(
    'php-standard-library.dev -> %s',
    $response->getFirstAnswerRecord(ARecord::class)?->address->toString() ?? '<unknown>',
);
