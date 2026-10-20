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

// 301 and 302 preserve PUT and its body; only POST changes to GET.
$client->send(new Message\Request(
    method: Message\METHOD_PUT,
    url: URL\parse('https://httpbin.org/redirect-to?url=/put&status_code=302'),
    body: new IO\MemoryHandle('payload'),
));
