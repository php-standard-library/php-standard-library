<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\TCP;
use Psl\TLS;

// Note: This example requires valid TLS certificate files to run.
// Replace the paths below with actual certificate and key files.
$certFile = '/etc/ssl/certs/server.pem';
$keyFile = '/etc/ssl/private/server.key';

$cert = TLS\Certificate::create($certFile, $keyFile);
$acceptor = new TLS\Acceptor(TLS\ServerConfiguration::create($cert)->withAlpnProtocols(['h2', 'http/1.1']));

// With ext-sockets, every accepted connection owns a private stream context, so the TLS
// options and peer certificate of one connection can never be observed by another.
$listener = TCP\listen('0.0.0.0', 8443);

while (true) {
    $stream = $listener->accept();
    $tls = $acceptor->accept($stream);
    // ... handle encrypted connection
    $tls->close();
}
