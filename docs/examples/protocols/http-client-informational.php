<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// Client-level callback fires for all requests
$client = new Client\Client(
    configuration: new Client\ClientConfiguration(onInformationalResponse: static function (Message\Response $response): void {
        if ($response->status === 103) {
            // Extract Link headers from 103 Early Hints for resource preloading
            foreach ($response->headers->getAll('link') as $link) {
                // e.g. "</style.css>; rel=preload; as=style"
                // Begin preloading the linked resource
                $link;
            }
        }
    }),
);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com'));

// Per-request callback fires after the client-level callback
$tx = $client->send($request, new Client\SendConfiguration(onInformationalResponse: static function (Message\Response $response): void {
    // This fires after the client-level callback for each 1xx response
    if ($response->status === 100) {
        // HTTP/1 limits each exchange to 16 informational responses.
        // Server acknowledged Expect: 100-continue, body will be sent
    }
}));

// Informational responses are also collected in the transaction
foreach ($tx->informational as $info) {
    $info->status; // 100, 102, 103, etc.
    $info->headers; // headers from the informational response
}

$tx->response->status; // final 2xx-5xx response
