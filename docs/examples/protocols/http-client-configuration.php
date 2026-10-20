<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\HTTP\Message\ProtocolVersion;
use Psl\TLS;
use Psl\URL;

$configuration = new Client\ClientConfiguration(
    maxResponseHeaderSize: 16_384,
    // HTTP/2 enforces this limit as DATA arrives, even before the body is read.
    maxResponseBodySize: 50_000_000,
    baseUrl: URL\parse('https://api.example.com/v2'),
    tlsConfiguration: new TLS\ClientConfiguration(minimumVersion: TLS\Version::Tls12),
    protocolVersions: [ProtocolVersion::V20, ProtocolVersion::V11],
);

// The pool keeps connections with different TLS or proxy settings separate.
$client = new Client\Client(configuration: $configuration);

$request = new Message\Request(method: Message\METHOD_GET, url: null, requestTarget: '/users');

$tx = $client->send($request);

$tx->response->status;
