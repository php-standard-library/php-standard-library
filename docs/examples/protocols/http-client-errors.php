<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Async;
use Psl\HTTP\Client;
use Psl\HTTP\Message;
use Psl\IO;
use Psl\Network;
use Psl\URL;

$client = new Client\RedirectClient(new Client\Client());

try {
    $tx = $client->send(new Message\Request(method: Message\METHOD_GET, url: URL\parse('https://example.com')));

    $tx->response->status;
} catch (Client\Exception\RequestException $e) {
    IO\write_line('Invalid request: %s', $e->getMessage());
} catch (Client\Exception\ProtocolException $e) {
    // HTTP/1 status codes must have exactly three digits in the range 100-999.
    IO\write_line('Bad response: %s', $e->getMessage());
} catch (Client\Exception\TooManyRedirectsException $e) {
    IO\write_line('Redirect loop: %s', $e->getMessage());
} catch (Network\Exception\RuntimeException $e) {
    IO\write_line('Connection failed: %s', $e->getMessage());
} catch (IO\Exception\RuntimeException $e) {
    IO\write_line('I/O error: %s', $e->getMessage());
} catch (Async\Exception\CancelledException) {
    IO\write_line('Request cancelled');
}
