<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

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

// Retry TCP after truncation, transport errors, SERVFAIL, or REFUSED.
// NXDOMAIN ends the query without a TCP retry.
$fallback = new DNS\FallbackResolver([$udp, $tcp]);

// Race multiple nameservers, return the fastest response
$racing = new DNS\RacingResolver([
    new DNS\UDPResolver('8.8.8.8'),
    new DNS\UDPResolver('1.1.1.1'),
]);
