<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HPACK;
use Psl\IO;

$encoder = new HPACK\Encoder(maxHeaderListSize: 64);
$decoder = new HPACK\Decoder();

try {
    $encoded = $encoder->encode([
        new HPACK\Header(':method', 'GET'),
        new HPACK\Header('x-extra', 'optional'),
    ]);
} catch (HPACK\Exception\HeaderListSizeException) {
    $encoded = $encoder->encode([new HPACK\Header(':method', 'GET')]);
}

foreach ($decoder->decode($encoded) as $header) {
    IO\write_line('%s: %s', $header->name, $header->value);
}
