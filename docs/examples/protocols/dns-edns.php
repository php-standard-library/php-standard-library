<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\DNS;
use Psl\DNS\EDNS;
use Psl\DNS\Record\RecordType;
use Psl\IP;
use Psl\SecureRandom;

$resolver = new DNS\SystemResolver();

// ECS source and scope prefixes must fit the address: 0..32 for IPv4, 0..128 for IPv6.
// Scope must be 0 in queries.
// Query with EDNS0 options
$response = $resolver->query('example.com', RecordType::A, ednsOptions: [
    new EDNS\CookieOption(clientCookie: SecureRandom\bytes(8)),
    new EDNS\ECSOption(IP\Address::parse('203.0.113.0'), sourcePrefixLength: 24, scopePrefixLength: 0),
    new EDNS\PaddingOption(128),
    new EDNS\NSIDOption(),
]);
