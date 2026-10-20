<?php

declare(strict_types=1);

namespace Psl\Message;

use Psl\IO;
use Psl\MIME\Headers;

use function strtolower;

/**
 * Serialize a message to a streaming RFC 5322 representation.
 *
 * Combines the message headers (with MIME-Version and body Content-Type)
 * and the body stream into a single readable handle. Message-level headers
 * such as "Content-Type" and "Content-Transfer-Encoding" are moved from the
 * message headers to the body part headers, and a "MIME-Version: 1.0" header
 * is inserted automatically. Bcc and Resent-Bcc headers are omitted to keep
 * blind recipients private. The message and its envelope are unchanged.
 *
 * The returned handle can be read incrementally or consumed in full via
 * {@see \Psl\IO\ReadHandleInterface::readAll()}.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322
 *
 * @api
 */
function serialize(MessageInterface $message): IO\ReadHandleInterface
{
    $pairs = [];
    foreach ($message->headers->pairs() as [$name, $value]) {
        $lower = strtolower($name);
        if ($lower === 'content-type' || $lower === 'content-transfer-encoding' || $lower === 'mime-version') {
            continue;
        }

        $pairs[] = [$name, $value];
    }

    $pairs[] = ['MIME-Version', '1.0'];

    foreach ($message->content->headers->pairs() as $pair) {
        $pairs[] = $pair;
    }

    $headers = Headers::fromPairs($pairs)->without('Bcc')->without('Resent-Bcc');

    return new IO\ConcatReadHandle(new IO\MemoryHandle($headers->toFoldedString() . "\r\n"), $message->content->body());
}
