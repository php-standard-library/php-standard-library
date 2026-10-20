<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\IO;
use Psl\Message\Address\Mailbox;
use Psl\Message\Envelope;
use Psl\Message\Message;
use Psl\MIME\Part;
use Psl\SMTP\Client\Transport;
use Psl\SMTP\Client\TransportConfiguration;
use Psl\SMTP\Security;

$transport = new Transport(
    TransportConfiguration::default()->withHost('smtp.example.com')->withSecurity(Security::TLS),
);

$sender = new Mailbox('alice', 'example.com', 'Alice');
// The envelope uses bob@xn--bcher-kva.example.
$recipient = new Mailbox('bob', 'bücher.example');

$message = new Message()
    ->withFrom($sender)
    ->withTo($recipient)
    ->withSubject('Hello from PSL')
    ->withContent(new Part\Text(new IO\MemoryHandle('Hello, Bob!')));

$report = $transport->send(Envelope::fromMessage($message), $message);

$transport->close();
