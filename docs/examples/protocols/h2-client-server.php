<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\H2;
use Psl\H2\Event;
use Psl\HPACK\Header;
use Psl\Network;

// Create a socket pair for client-server communication
[$clientSocket, $serverSocket] = Network\socket_pair();

$client = new H2\ClientConnection($clientSocket);
$server = new H2\ServerConnection($serverSocket);

// Initialize both sides (sends connection preface + SETTINGS)
$client->initialize();
$server->initialize();

// Complete the handshake
$server->readClientPreface();
$server->readEvent(); // client SETTINGS
$client->readEvent(); // server SETTINGS
$client->readEvent(); // SETTINGS ACK
$server->readEvent(); // SETTINGS ACK

// Client sends a request
$streamId = $client->nextStreamId();
$client->sendHeaders(
    $streamId,
    [
        new Header(':method', 'GET'),
        new Header(':path', '/hello'),
        new Header(':scheme', 'https'),
        new Header(':authority', 'example.com'),
        // The te value is case-insensitive; other connection-specific fields cause StreamException.
        // Values with CR, LF, NUL, or leading or trailing spaces or tabs also fail before sending.
        new Header('te', 'Trailers'),
    ],
    endStream: true,
);

// Server reads the request
$events = $server->readEvent();
foreach ($events as $event) {
    if (!$event instanceof Event\HeadersReceived) {
        continue;
    }

    // Server sends a response
    $server->sendHeadersWithStatus($event->streamId, '200', [
        new Header('content-type', 'text/plain'),
    ]);

    $server->sendData($event->streamId, 'Hello, World!', endStream: true);
}

// A lower receive frame-size limit takes effect when the peer acknowledges it.
$client->sendSettings([H2\Setting::MaxFrameSize->value => 16_384]);

// The decoder also waits for ACK before enforcing a lower table-size limit.
$client->sendSettings([H2\Setting::HeaderTableSize->value => 1024]);
