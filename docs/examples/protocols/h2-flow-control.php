<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\H2;
use Psl\HPACK\Header;
use Psl\Str;

/** @var H2\ClientConnectionInterface $client */
/** @var H2\ServerConnectionInterface $server */

$streamId = $client->nextStreamId();
$client->sendHeaders($streamId, [
    new Header(':method', 'POST'),
    new Header(':path', '/upload'),
    new Header(':scheme', 'https'),
    new Header(':authority', 'example.com'),
]);

// sendData splits frames but requires enough window for the whole payload.
// sendAllData also waits for flow-control window updates.
// It throws H2\Exception\StreamException if the stream closes while waiting.
// GOAWAY, EOF, or a read failure instead throws H2\Exception\ConnectionException.
$largePayload = Str\repeat('data', 10_000);
$client->sendAllData($streamId, $largePayload, endStream: true);
