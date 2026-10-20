<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\IO;
use Psl\Message;
use Psl\MIME\Part;

// Header values must not contain CR, LF, or NUL.
// Mailbox quotes and escapes display names for use in headers.
// Addresses accept strings, Mailbox, or AddressList
$message = new Message\Message()
    ->withFrom('Alice <alice@example.com>')
    ->withTo('bob@example.com, carol@example.com')
    ->withSubject('Hello from PSL')
    ->withDate(Psl\DateTime\DateTime::now())
    ->withGeneratedMessageId()
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello, World!')));

$message->from; // AddressList
$message->to; // AddressList
$message->subject; // "Hello from PSL"
$message->messageId; // MessageId
$message->content; // PartInterface (the text part)

// Groups quote punctuation in their names and preserve member display names.
$team = new Message\Address\Group('Team: Europe', [
    new Message\Address\Mailbox('alice', 'example.com', 'Doe, Alice'),
]);
$team->toString(); // '"Team: Europe": "Doe, Alice" <alice@example.com>;'
