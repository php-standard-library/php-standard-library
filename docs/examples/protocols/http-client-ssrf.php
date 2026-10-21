<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// The preset also blocks shared, reserved, documentation, multicast, translation,
// 6to4, Teredo, discard-only, and deprecated site-local ranges. Unix sockets have
// no peer IP and do not undergo these checks. Behind a proxy the check sees the proxy.
$client = new Client\Client(middleware: [Client\Middleware\DeniedDestinationsMiddleware::forPrivateNetworkRanges()]);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));
$tx = $client->send($request);

// IPv4-mapped IPv6 receives the same checks as its IPv4 address.
$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('http://[::ffff:169.254.169.254]/metadata'));
$tx = $client->send($request);
