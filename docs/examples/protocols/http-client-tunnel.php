<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$configuration = new Client\ClientConfiguration(
    proxyConfiguration: new Client\ProxyConfiguration(
        URL\parse('http://proxy.example.com:8080'),
        skipProxyFor: [
            'localhost',
            '.internal.example.com',
        ],
    ),
);

// CONNECT leaves all bytes after the proxy headers for the tunnel.
$client = new Client\Client(configuration: $configuration);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));
$tx = $client->send($request);

$tx->response->status;
