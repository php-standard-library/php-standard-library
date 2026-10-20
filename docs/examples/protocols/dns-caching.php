<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Cache;
use Psl\DNS;

// Wrap any resolver with caching
$resolver = new DNS\CachedResolver(new DNS\SystemResolver(), new Cache\LocalStore(), failureTtl: 5);

// First query hits the network
$response = $resolver->query('example.com', DNS\Record\RecordType::A);

// Cached records carry their remaining TTL.
// A positive TTL lets the second query use the cache.
// SERVFAIL, REFUSED, DNS runtime errors and timeouts use the five-second failure TTL.
// Zero-TTL answers require a new query.
$cached = $resolver->query('example.com', DNS\Record\RecordType::A);
