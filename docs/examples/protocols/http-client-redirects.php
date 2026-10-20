<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\URL;

$client = new Client\RedirectClient(new Client\Client(), maxRedirects: 5, autoReferrer: true);

$request = new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://httpbin.org/redirect/3'));

$tx = $client->send($request);

$tx->response->status;

// A seekable body lets RedirectClient resend it after a 307 or 308 response.
$upload = new Message\Request(
    method: Message\METHOD_POST,
    url: URL\parse('https://httpbin.org/redirect-to?url=/post&status_code=307'),
    body: new IO\MemoryHandle('payload'),
);

$client->send($upload);
