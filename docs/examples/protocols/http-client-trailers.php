<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Async;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$client = new Client\Client();

// Sending request trailers using an Awaitable<FieldMap>.
// Invalid trailer names and CR/LF in fields throw RequestException in HTTP/1.
// Trailers are sent after the body with chunked transfer encoding (H1) or after DATA frames (H2).
$deferred = new Async\Deferred();
$request = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://example.com/upload'),
    headers: new Message\FieldMap([
        ['content-type', 'application/octet-stream'],
        ['trailer',      'checksum'],
    ]),
    body: new IO\MemoryHandle('file contents here'),
    trailers: $deferred->getAwaitable(),
);

// Resolve the trailers after the body is ready to be sent.
// In practice, you would compute the checksum while streaming the body.
$deferred->complete(new Message\FieldMap([['checksum', 'sha256=abc123...']]));

$tx = $client->send($request);

// Reading response trailers. Trailers are available after the body is fully consumed.
$body = $tx->response->body?->readAll();

if ($tx->response->trailers !== null) {
    $trailerFields = $tx->response->trailers->await();
    $trailerFields->get('server-timing'); // e.g. "db;dur=53"
}
