<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

$client = new Client\Client(
    configuration: new Client\ClientConfiguration(protocolVersions: [Message\ProtocolVersion::V20]),
);

// A failed exchange is retried at most once, and only if the body is absent or rewinds.
// REFUSED_STREAM or a GOAWAY above the stream retries any method (provably unprocessed);
// a connection-level failure before any response retries GET/HEAD/OPTIONS/TRACE or an
// Idempotency-Key request.
$tx = $client->send(new Message\Request(method: Message\METHOD_GET, url: URL\parse('http://localhost:8080/')));

$tx->response->protocolVersion; // ProtocolVersion::V20
$tx->response->status; // 200
$tx->response->body?->readAll();
