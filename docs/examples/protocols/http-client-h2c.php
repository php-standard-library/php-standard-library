<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\Client(
    configuration: new Client\ClientConfiguration(protocolVersions: [Message\ProtocolVersion::V20]),
);

// GOAWAY and REFUSED_STREAM allow one retry only when the server did not process the request.
// A request body must be seekable and rewound to its original position.
$tx = $client->send(new Message\Request(method: Message\METHOD_GET, url: URL\parse('http://localhost:8080/')));

$tx->response->protocolVersion; // ProtocolVersion::V20
$tx->response->status; // 200
$tx->response->body?->readAll();
