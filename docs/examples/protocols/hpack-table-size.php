<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\HPACK;
use Psl\IO;

$encoder = new HPACK\Encoder();
$decoder = new HPACK\Decoder();

// Allow the peer to select a larger table.
$decoder->resize(8192);

// The encoder selects that size and sends its update in the next block.
$encoder->resize(8192);
$encoded = $encoder->encode([new HPACK\Header(':method', 'GET')]);

foreach ($decoder->decode($encoded) as $header) {
    IO\write_line('%s: %s', $header->name, $header->value);
}
