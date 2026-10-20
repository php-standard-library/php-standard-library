<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Crypto\Symmetric;
use Psl\IO;

$key = Symmetric\generate_key();
$streamEncryptor = new Symmetric\StreamEncryptor($key);

// Encrypt in chunks, filling short reads until the source reaches EOF.
$source = new IO\MemoryHandle('This is a large message that will be encrypted in chunks.');
$encrypted = new IO\MemoryHandle();

$streamEncryptor->copySealed($source, $encrypted, chunkSize: 4096);

// Decrypt the stream back
$encrypted->seek(0);
$decrypted = new IO\MemoryHandle();
$streamEncryptor->copyOpened($encrypted, $decrypted);

$decrypted->seek(0);
IO\write_line('Decrypted: %s', $decrypted->readAll());
