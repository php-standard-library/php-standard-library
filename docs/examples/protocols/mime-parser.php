<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\IO;
use Psl\MIME\MultiPart\Parser;

$body = "--boundary\r\nContent-Type: text/plain\r\n\r\nHello\r\n--boundary\r\nContent-Type: text/html\r\n\r\n<b>World</b>\r\n--boundary--\r\n";

$parser = new Parser(
    boundary: 'boundary',
    maxParts: 100,
    maxPartSize: 10_000_000,
    spoolThreshold: 2_097_152,
    maxHeaderSize: 16_384,
);

foreach ($parser->parse(new IO\MemoryHandle($body)) as $part) {
    IO\write_line('%s: %s', $part->mediaType->essence(), $part->body()->readAll());
}
