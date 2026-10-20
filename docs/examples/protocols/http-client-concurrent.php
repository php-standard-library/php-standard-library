<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Async;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\URL;

// HTTP/2 queues requests at the peer limit until an active stream closes.
// Cancellation interrupts these waits; close an unread body to cancel its stream.

$client = new Client\Client();

$transactions = Async\concurrently([
    'users' => static fn() => $client->send(new Message\Request(
        method: Message\METHOD_GET,
        url: URL\parse('https://httpbin.org/get?resource=users'),
    )),
    'posts' => static fn() => $client->send(new Message\Request(
        method: Message\METHOD_GET,
        url: URL\parse('https://httpbin.org/get?resource=posts'),
    )),
    'comments' => static fn() => $client->send(new Message\Request(
        method: Message\METHOD_GET,
        url: URL\parse('https://httpbin.org/get?resource=comments'),
    )),
]);

$transactions['users']->response->status;
$transactions['posts']->response->body?->readAll();
