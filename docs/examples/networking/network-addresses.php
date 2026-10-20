<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Network\Address;

$tcp = Address::tcp('127.0.0.1', 8080);
$udp = Address::udp('0.0.0.0', 9999);
$unix = Address::unix('/tmp/my-app.sock');

echo $tcp->toString(); // "tcp://127.0.0.1:8080"

$ipv6 = Address::tcp('::1', 8080);
echo $ipv6->host; // "::1"
echo $ipv6->toString(); // "tcp://[::1]:8080"
