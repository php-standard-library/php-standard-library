<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\IO;
use Psl\Message;
use Psl\MIME\Part;

$message = new Message\Message()
    ->withFrom('alice@example.com')
    ->withTo('bob@example.com')
    ->withBcc('secret@example.com')
    ->withSubject('Round trip')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello!')));

// Keep blind recipients in the envelope; omit them from the wire headers.
$envelope = Message\Envelope::fromMessage($message);

// Serialize to a streaming handle (headers + body)
$handle = Message\serialize($message);

// Parse back from a string or handle
$parsed = Message\parse($handle);

$parsed->subject; // "Round trip"
$parsed->from?->mailboxes()[0]?->address; // "alice@example.com"
$parsed->content->body()->readAll(); // encoded body content

// Received headers keep usable fields and recipients.
$received = Message\parse("Subject : hello\r\nTo: alice@example.com, invalid\r\n\r\nbody");
$received->subject; // "hello"
$received->to?->toString(); // "alice@example.com"
