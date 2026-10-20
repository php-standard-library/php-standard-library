<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\H2;
use Psl\HPACK\Header;

/** @var H2\ServerConnectionInterface $server */
/** @var H2\ClientConnectionInterface $client */

// Server pushes a CSS file alongside the HTML response
$requestStreamId = 1; // client-initiated stream
$pushStreamId = $server->nextStreamId();

$server->sendPushPromise($requestStreamId, $pushStreamId, [
    new Header(':method', 'GET'),
    new Header(':path', '/style.css'),
    new Header(':scheme', 'https'),
    new Header(':authority', 'example.com'),
]);

// Send the pushed response
$server->sendHeadersWithStatus($pushStreamId, '200', [
    new Header('content-type', 'text/css'),
]);
$server->sendData($pushStreamId, 'body { color: red; }', endStream: true);

// Check the promised origin against the server's authority before using a push.
// Reset an unauthorized promised stream with H2\ErrorCode::ProtocolError.

// Client can reject unwanted pushes
$client->rejectPush($pushStreamId);
