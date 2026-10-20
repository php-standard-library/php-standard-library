<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\CIDR;

$private = new CIDR\Block('10.0.0.0/8');
$private->contains('10.1.2.3'); // true
$private->contains('172.16.0.1'); // false
$private->contains('::ffff:10.1.2.3'); // false: different address family

$subnet = new CIDR\Block('192.168.1.5/24'); // Masks host bits to 192.168.1.0/24.
$subnet->contains('192.168.1.0'); // true
$subnet->contains('192.168.1.255'); // true
